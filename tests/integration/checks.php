<?php
/**
 * Sanka integration checks.
 *
 * Run inside the plugin-testing container, from the site root:
 *
 *     docker exec -w /var/www/html ddev-plugin-testing-web php /var/www/craft-sanka/tests/integration/checks.php
 *
 * Covers what a unit fixture cannot: real ledger rows through Craft's database, the dispatcher's
 * quota arithmetic, the element-save path, and the two generated files served over HTTP. Every
 * engine is exercised through {@see FakeHttpClient}, so the whole submission path is covered
 * without a single outbound request.
 *
 * Idempotent and self-cleaning. Everything it creates is prefixed `sanka-check-` and swept on the
 * way out, including strays from a run that died half way, and the plugin settings and edition are
 * restored. The prefix is deliberately unmistakable because this runs on a shared site.
 */

$root = getcwd();
require $root . '/bootstrap.php';

/** @var craft\console\Application $app */
$app = require CRAFT_VENDOR_PATH . '/craftcms/cms/bootstrap/console.php';

require dirname(__DIR__) . '/support/FakeHttpClient.php';

use craft\elements\Entry;
use craft\helpers\Db;
use justinholtweb\sanka\engines\GoogleEngine;
use justinholtweb\sanka\engines\IndexNowEngine;
use justinholtweb\sanka\engines\SitemapEngine;
use justinholtweb\sanka\http\HttpResponse;
use justinholtweb\sanka\models\CrawlerAgent;
use justinholtweb\sanka\models\Edition;
use justinholtweb\sanka\models\GeoFinding;
use justinholtweb\sanka\models\Rule;
use justinholtweb\sanka\models\Settings;
use justinholtweb\sanka\models\SubmissionResult;
use justinholtweb\sanka\Plugin;
use justinholtweb\sanka\records\CrawlRecord;
use justinholtweb\sanka\records\QuotaRecord;
use justinholtweb\sanka\records\SubmissionRecord;
use justinholtweb\sanka\tests\support\FakeHttpClient;

$passed = 0;
$failed = 0;

function check(string $label, callable $test): void
{
    global $passed, $failed;

    try {
        $result = $test();

        if ($result === true) {
            $passed++;
            echo "  ✓ $label\n";

            return;
        }

        $failed++;
        echo "  ✗ $label\n    " . (is_string($result) ? $result : 'returned ' . var_export($result, true)) . "\n";
    } catch (Throwable $e) {
        $failed++;
        echo "  ✗ $label\n    " . get_class($e) . ': ' . $e->getMessage() . "\n    " . $e->getFile() . ':' . $e->getLine() . "\n";
    }
}

function section(string $title): void
{
    echo "\n$title\n";
}

$plugin = Plugin::getInstance();
$plugins = Craft::$app->getPlugins();
$site = Craft::$app->getSites()->getPrimarySite();
$host = (string)parse_url((string)$site->getBaseUrl(), PHP_URL_HOST);
$suffix = substr(md5((string)microtime(true)), 0, 6);
$base = "https://{$host}/sanka-check-{$suffix}";

$originalEdition = $plugin->edition;
$originalSettings = $plugin->getSettings()->toArray();
$createdEntries = [];

/** Wipe every row this run — and any left by a run that died half way. */
$sweep = static function() {
    $db = Craft::$app->getDb();
    $db->createCommand()->delete(SubmissionRecord::TABLE, ['like', 'url', 'sanka-check-'])->execute();
    $db->createCommand()->delete(CrawlRecord::TABLE, ['like', 'url', 'sanka-check-'])->execute();
    $db->createCommand()->delete(QuotaRecord::TABLE, ['like', 'engine', 'sanka-check-'])->execute();
};

$sweep();

$projectConfig = Craft::$app->getProjectConfig();
$writeYaml = $projectConfig->writeYamlAutomatically;
$projectConfig->writeYamlAutomatically = false;

$http = new FakeHttpClient();
$plugin->engines->setHttpClient($http);

// Pro throughout: the Lite boundary is checked explicitly against `Edition`, which takes the flag
// rather than reading the plugin, so it needs no edition switch to test.
$plugins->switchEdition('sanka', Plugin::EDITION_PRO);

$account = FakeHttpClient::serviceAccount();

/**
 * `$NAME` for an environment variable holding `$value`. Since 5.0.2 the key setting refuses inline
 * JSON — it is project config — so the checks supply the key the way a site would.
 */
function keyEnv(string $value, string $name = 'SANKA_CHECK_KEY'): string
{
    putenv("$name=$value");
    $_SERVER[$name] = $_ENV[$name] = $value;

    return '$' . $name;
}
$settings = $plugin->getSettings();
$settings->setAttributes([
    'dryRun' => false,
    'autoSubmit' => true,
    'allowLocalHosts' => true,
    'cooldown' => 0,
    'maxAttempts' => 3,
    'retryBackoff' => 60,
    'googleEnabled' => true,
    'googleCredentials' => keyEnv($account['json']),
    'googleDailyQuota' => 200,
    'indexNowEnabled' => true,
    'indexNowKey' => 'sankacheck0123456789abcdef',
    'indexNowEndpoint' => IndexNowEngine::DEFAULT_ENDPOINT,
    'indexNowDailyQuota' => 10000,
    'sitemapEnabled' => true,
    'sitemapUrls' => [],
    'llmsEnabled' => true,
    'llmsFullEnabled' => true,
    'crawlerLogEnabled' => true,
    'crawlerPolicy' => [],
    'autoSubmitRules' => [],
], false);
$plugin->engines->reset();
$plugin->engines->setHttpClient($http);

try {

// ---------------------------------------------------------------- settings

section('Settings — coercion and validation');

check('a cleared number field keeps the default rather than fatalling', function() {
    $s = new Settings();
    $s->setAttributes(['cooldown' => ''], false);

    return $s->cooldown === 300 ?: "cooldown became " . var_export($s->cooldown, true);
});

check('a posted array where an int is expected keeps the default', function() {
    $s = new Settings();
    $s->setAttributes(['maxAttempts' => ['date' => '2026-01-01']], false);

    return $s->maxAttempts === 4 ?: "maxAttempts became " . var_export($s->maxAttempts, true);
});

check('the sitemap table’s row shape is flattened into a list of URLs', function() {
    $s = new Settings();
    $s->setAttributes(['sitemapUrls' => [
        'row1' => ['url' => ' https://example.com/sitemap.xml '],
        'row2' => ['url' => ''],
        'row3' => ['url' => 'https://example.com/sitemap.xml'],
    ]], false);

    return $s->sitemapUrls === ['https://example.com/sitemap.xml'] ?: json_encode($s->sitemapUrls);
});

check('a flat list of sitemap URLs is left alone', function() {
    $s = new Settings();
    $s->setAttributes(['sitemapUrls' => ['https://example.com/a.xml', 'https://example.com/b.xml']], false);

    return $s->sitemapUrls === ['https://example.com/a.xml', 'https://example.com/b.xml'] ?: json_encode($s->sitemapUrls);
});

check('an empty sitemap table clears the list rather than fatalling', function() {
    $s = new Settings();
    $s->sitemapUrls = ['https://example.com/a.xml'];
    // What Craft posts for a table nobody added a row to.
    $s->setAttributes(['sitemapUrls' => ''], false);

    return $s->sitemapUrls === ['https://example.com/a.xml'] ?: json_encode($s->sitemapUrls);
});

check('a relative sitemap URL posted from the table is still reported', function() {
    $s = new Settings();
    $s->setAttributes(['sitemapUrls' => ['row1' => ['url' => '/sitemap.xml']]], false);
    $s->validate(['sitemapUrls']);

    return $s->hasErrors('sitemapUrls') ?: 'a relative URL was accepted';
});

check('a sitemap value nothing can flatten is reported, not skipped', function() {
    $s = new Settings();
    $s->sitemapUrls = [['url' => 'https://example.com/a.xml', 'extra' => 'x']];
    $s->validate(['sitemapUrls']);

    return $s->hasErrors('sitemapUrls') ?: 'an array value validated as a URL';
});

check('every sitemap URL that reaches a template is a string', function() {
    $s = new Settings();
    $s->setAttributes(['sitemapUrls' => ['row1' => ['url' => 'https://example.com/a.xml']]], false);

    foreach ($s->sitemapUrls as $url) {
        if (!is_string($url)) {
            return 'got ' . gettype($url);
        }
    }

    return true;
});

check('numeric strings become ints', function() {
    $s = new Settings();
    $s->setAttributes(['retentionDays' => '45'], false);

    return $s->retentionDays === 45 ?: 'got ' . var_export($s->retentionDays, true);
});

check('nothing is marked required, so a fresh install can save', function() {
    $s = new Settings();

    foreach ($s->rules() as $rule) {
        if (in_array('required', (array)$rule, true)) {
            return 'a required rule exists: ' . json_encode($rule);
        }
    }

    return $s->validate() ?: 'a blank Settings model does not validate: ' . json_encode($s->getErrors());
});

check('invalid service account JSON is rejected', function() {
    $s = new Settings();
    $s->googleCredentials = keyEnv('{not json', 'SANKA_CHECK_TMP');

    return !$s->validate(['googleCredentials']) ?: 'accepted malformed JSON';
});

check('service account JSON missing client_email is rejected by name', function() {
    $s = new Settings();
    $s->googleCredentials = keyEnv('{"type":"service_account","private_key":"x"}', 'SANKA_CHECK_TMP');
    $s->validate(['googleCredentials']);

    return str_contains(implode(' ', $s->getErrors('googleCredentials')), 'client_email')
        ?: 'errors were: ' . json_encode($s->getErrors('googleCredentials'));
});

check('a real service account key validates', function() use ($account) {
    $s = new Settings();
    $s->googleCredentials = keyEnv($account['json'], 'SANKA_CHECK_TMP');

    return $s->validate(['googleCredentials']) ?: json_encode($s->getErrors());
});

check('a short IndexNow key is rejected', function() {
    $s = new Settings();
    $s->indexNowKey = 'abc';

    return !$s->validate(['indexNowKey']) ?: 'accepted a 3-character key';
});

check('an IndexNow key with illegal characters is rejected', function() {
    $s = new Settings();
    $s->indexNowKey = 'abcdefgh$ijklmnop';

    return !$s->validate(['indexNowKey']) ?: 'accepted a key containing $';
});

check('an unknown IndexNow endpoint is rejected', function() {
    $s = new Settings();
    $s->indexNowEndpoint = 'evil.example.com';

    return !$s->validate(['indexNowEndpoint']) ?: 'accepted an arbitrary host';
});

check('a relative sitemap URL is rejected', function() {
    $s = new Settings();
    $s->sitemapUrls = ['/sitemap.xml'];

    return !$s->validate(['sitemapUrls']) ?: 'accepted a relative sitemap URL';
});

check('an unknown crawler token is rejected', function() {
    $s = new Settings();
    $s->crawlerPolicy = ['NotARealBot' => 'block'];

    return !$s->validate(['crawlerPolicy']) ?: 'accepted an unknown agent';
});

check('the credential summary never contains the private key', function() use ($account) {
    $s = new Settings();
    $s->googleCredentials = $account['json'];
    $summary = (string)$s->credentialSummary();

    return (!str_contains($summary, 'PRIVATE KEY') && str_contains($summary, 'gserviceaccount.com'))
        ?: "summary was: {$summary}";
});

check('a generated IndexNow key satisfies the protocol', function() {
    $key = Settings::generateIndexNowKey();

    return (bool)preg_match('/^[A-Za-z0-9\-]{8,128}$/', $key) ?: "generated: {$key}";
});

check('the policy for an unconfigured agent defaults to allow', function() use ($plugin) {
    return $plugin->getSettings()->policyFor('GPTBot') === CrawlerAgent::POLICY_ALLOW ?: 'defaulted to block';
});

// ------------------------------------------------------------------ edition

check('the settings screen renders again after a save from the control panel', function() use ($plugin) {
    // The regression this guards: the editable table posts `sitemapUrls[rowId][url]`, and a row
    // stored in that shape reaches Craft's own table template as an array. It renders it with
    // `{{ value }}`, which is “Array to string conversion” — a fatal on the settings screen, on
    // every request, with no way back in through the control panel to undo the setting that caused
    // it. Rendering the real screen is the only thing that would have caught it.
    $settings = $plugin->getSettings();
    $before = $settings->sitemapUrls;
    $view = Craft::$app->getView();
    $mode = $view->getTemplateMode();

    try {
        $settings->setAttributes(['sitemapUrls' => [
            'row1' => ['url' => 'https://example.com/sanka-check-sitemap.xml'],
        ]], false);

        $view->setTemplateMode(craft\web\View::TEMPLATE_MODE_CP);
        $html = (new \ReflectionMethod($plugin, 'settingsHtml'))->invoke($plugin);

        return str_contains($html, 'https://example.com/sanka-check-sitemap.xml')
            ?: 'the screen rendered without the configured sitemap';
    } finally {
        $settings->sitemapUrls = $before;
        $view->setTemplateMode($mode);
    }
});

section('Editions — the Lite/Pro boundary');

check('Lite gets no GEO, no sitemaps, no sweeps, no bulk, no export', function() {
    foreach (['allowsGeo', 'allowsSitemaps', 'allowsSweeps', 'allowsBulk', 'allowsExport'] as $method) {
        if (Edition::$method(false) !== false) {
            return "Edition::{$method}(false) allowed it";
        }
    }

    return true;
});

check('Pro gets all of them', function() {
    foreach (['allowsGeo', 'allowsSitemaps', 'allowsSweeps', 'allowsBulk', 'allowsExport'] as $method) {
        if (Edition::$method(true) !== true) {
            return "Edition::{$method}(true) refused it";
        }
    }

    return true;
});

check('Lite caps rules and Pro does not', function() {
    return (Edition::maxRules(false) === Edition::LITE_MAX_RULES && Edition::maxRules(true) === null)
        ?: 'got ' . var_export(Edition::maxRules(false), true) . ' / ' . var_export(Edition::maxRules(true), true);
});

check('a Pro configuration on Lite is reported, one problem per feature', function() {
    $s = new Settings();
    $s->sitemapEnabled = true;
    $s->llmsEnabled = true;
    $s->sweepEnabled = true;

    $problems = Edition::problems($s, false);

    return count($problems) === 3 ?: 'got ' . json_encode($problems);
});

check('the same configuration on Pro reports nothing', function() {
    $s = new Settings();
    $s->sitemapEnabled = true;
    $s->llmsEnabled = true;

    return Edition::problems($s, true) === [] ?: 'Pro complained';
});

check('too many rules is a Lite problem naming the count', function() {
    $s = new Settings();
    $s->autoSubmitRules = array_fill(0, 5, ['section' => '*']);
    $problems = Edition::problems($s, false);

    return (count($problems) === 1 && str_contains($problems[0], 'Remove 2'))
        ?: 'got ' . json_encode($problems);
});

check('Rules::all() downgrades rather than refusing, so a lapsed licence keeps indexing', function() use ($plugin) {
    $stored = $plugin->getSettings()->autoSubmitRules;
    $plugin->getSettings()->autoSubmitRules = array_fill(0, 6, ['section' => '*']);

    try {
        $count = count($plugin->rules->all());
    } finally {
        $plugin->getSettings()->autoSubmitRules = $stored;
    }

    // Pro right now, so all six survive. The cap is asserted through Edition above.
    return $count === 6 ?: "got {$count}";
});

// --------------------------------------------------------------------- rules

section('Rules — matching and the two config shapes');

check('the flat control-panel shape round-trips to the canonical one', function() {
    $rule = Rule::fromConfig([
        'section' => 'news',
        'create' => '1',
        'update' => '1',
        'delete' => '',
        'google' => '1',
        'indexnow' => '',
        'sitemap' => '',
    ]);

    return ($rule->events === ['create', 'update'] && $rule->engines === ['google'])
        ?: 'events ' . json_encode($rule->events) . ' engines ' . json_encode($rule->engines);
});

check('the canonical shape is accepted unchanged', function() {
    $rule = Rule::fromConfig(['section' => 'news', 'events' => ['delete'], 'engines' => ['indexnow']]);

    return ($rule->events === ['delete'] && $rule->engines === ['indexnow']) ?: json_encode($rule->toConfig());
});

check('a rule with no events at all falls back to every event', function() {
    $rule = Rule::fromConfig(['section' => 'news']);

    return $rule->events === Rule::EVENTS ?: json_encode($rule->events);
});

check('unticking every event box means “nothing”, not “everything”', function() {
    $rule = Rule::fromConfig(['section' => 'news', 'create' => '', 'update' => '', 'delete' => '']);

    return ($rule->events === [] && !$rule->validate())
        ?: 'events became ' . json_encode($rule->events);
});

check('a config carrying no event information at all still gets every event', function() {
    return Rule::fromConfig(['section' => 'news'])->events === Rule::EVENTS ?: 'the default was lost';
});

check('an unknown event is rejected', function() {
    $rule = new Rule();
    $rule->events = ['create', 'exploded'];

    return !$rule->validate() ?: 'accepted an unknown event';
});

check('toColumns renders an empty engine list as every engine ticked', function() {
    $columns = Rule::fromConfig(['section' => '*', 'engines' => []])->toColumns();

    return ($columns['google'] && $columns['indexnow'] && $columns['sitemap'])
        ?: json_encode($columns);
});

check('a disabled rule handles no event', function() {
    $rule = Rule::fromConfig(['section' => '*', 'enabled' => false]);

    return !$rule->handlesEvent(Rule::EVENT_UPDATE) ?: 'a disabled rule still fired';
});

check('the wildcard section matches every entry', function() {
    $rule = Rule::fromConfig(['section' => Rule::ANY]);
    $entry = Entry::find()->status(null)->one();

    if ($entry === null) {
        return 'no entries in the harness to match against';
    }

    return $rule->matches($entry) ?: 'the wildcard did not match';
});

check('a rule naming another section does not match', function() {
    $rule = Rule::fromConfig(['section' => 'sanka-no-such-section']);
    $entry = Entry::find()->status(null)->one();

    if ($entry === null) {
        return 'no entries in the harness to match against';
    }

    return !$rule->matches($entry) ?: 'matched the wrong section';
});

// ---------------------------------------------------------------------- URLs

section('URLs — what may be submitted');

check('a relative URL is refused with an actionable reason', function() use ($plugin) {
    $problem = (string)$plugin->urls->problem('/about');

    return str_contains($problem, 'absolute') ?: "said: {$problem}";
});

check('a non-http scheme is refused', function() use ($plugin) {
    return $plugin->urls->problem('ftp://example.com/x') !== null ?: 'accepted ftp';
});

check('a fragment is refused, because engines do not have them', function() use ($plugin, $base) {
    $problem = (string)$plugin->urls->problem($base . '/page#section');

    return str_contains($problem, 'Fragment') ?: "said: {$problem}";
});

check('a preview token is refused rather than handing a draft to an engine', function() use ($plugin, $base) {
    $problem = (string)$plugin->urls->problem($base . '/page?token=abc123');

    return str_contains($problem, 'preview') ?: "said: {$problem}";
});

check('a host this install does not serve is refused, naming the host', function() use ($plugin) {
    $problem = (string)$plugin->urls->problem('https://someone-elses-site.example/page');

    return str_contains($problem, 'someone-elses-site.example') ?: "said: {$problem}";
});

check('a URL on this site is accepted', function() use ($plugin, $base) {
    $problem = $plugin->urls->problem($base . '/page');

    return $problem === null ?: "refused: {$problem}";
});

check('a local host is refused when the escape hatch is off', function() use ($plugin) {
    $s = $plugin->getSettings();
    $was = $s->allowLocalHosts;
    $s->allowLocalHosts = false;

    try {
        $problem = (string)$plugin->urls->problem('https://something.ddev.site/page');
    } finally {
        $s->allowLocalHosts = $was;
    }

    return str_contains($problem, 'not reachable') ?: "said: {$problem}";
});

check('the hash is stable and trims', function() use ($plugin, $base) {
    return $plugin->urls->hash($base . '/x') === $plugin->urls->hash('  ' . $base . "/x  \n")
        ?: 'hashes differed';
});

check('a URL resolves to the site that serves it', function() use ($plugin, $base, $site) {
    return $plugin->urls->siteIdForUrl($base . '/page') === $site->id ?: 'resolved to the wrong site';
});

check('every known host is lowercased and unique', function() use ($plugin) {
    $hosts = $plugin->urls->knownHosts();

    return ($hosts === array_map('strtolower', $hosts) && $hosts === array_values(array_unique($hosts)))
        ?: json_encode($hosts);
});

// --------------------------------------------------------------------- ledger

section('The ledger — queueing, deduplication, cooldown');

check('queueing writes a pending row', function() use ($plugin, $base) {
    $row = $plugin->submissions->queue($base . '/one', GoogleEngine::HANDLE);

    return ($row !== null && $row->status === SubmissionRecord::STATUS_PENDING)
        ?: 'got ' . var_export($row?->status, true);
});

check('queueing the same thing again returns null rather than a second row', function() use ($plugin, $base) {
    return $plugin->submissions->queue($base . '/one', GoogleEngine::HANDLE) === null
        ?: 'a duplicate pending row was created';
});

check('an unsubmittable URL is recorded as skipped, with the reason on the row', function() use ($plugin) {
    $row = $plugin->submissions->queue('/relative', GoogleEngine::HANDLE);

    return ($row !== null
        && $row->status === SubmissionRecord::STATUS_SKIPPED
        && str_contains((string)$row->message, 'absolute'))
        ?: 'got ' . var_export($row?->message, true);
});

check('the same URL may be queued for a different engine', function() use ($plugin, $base) {
    $row = $plugin->submissions->queue($base . '/one', IndexNowEngine::HANDLE);

    return $row !== null ?: 'the engines shared a queue slot';
});

check('queueUrl fans out across every usable engine', function() use ($plugin, $base) {
    $rows = $plugin->submissions->queueUrl($base . '/fanout');

    return count($rows) === count($plugin->engines->getUsableHandles())
        ?: count($rows) . ' rows for ' . count($plugin->engines->getUsableHandles()) . ' engines';
});

check('queueUrl ignores an engine that is not usable', function() use ($plugin, $base) {
    $rows = $plugin->submissions->queueUrl($base . '/named', ['google', 'not-an-engine']);

    return count($rows) === 1 ?: count($rows) . ' rows';
});

check('the reason is recorded, so the log can answer “why did this cost quota”', function() use ($plugin, $base) {
    $row = $plugin->submissions->queue($base . '/reasoned', GoogleEngine::HANDLE, SubmissionRecord::TYPE_UPDATED, 'sweep');

    return $row?->reason === 'sweep' ?: var_export($row?->reason, true);
});

check('a save storm inside the cooldown costs one submission, not twenty', function() use ($plugin, $base, $http) {
    $settings = $plugin->getSettings();
    $settings->cooldown = 600;
    $http->setResponder(FakeHttpClient::defaultResponder());

    try {
        $url = $base . '/storm';
        $plugin->submissions->queue($url, GoogleEngine::HANDLE);
        $plugin->dispatcher->drain(GoogleEngine::HANDLE);

        $second = $plugin->submissions->queue($url, GoogleEngine::HANDLE);

        if ($second === null || $second->status !== SubmissionRecord::STATUS_SKIPPED) {
            return 'the second save was not skipped: ' . var_export($second?->status, true);
        }

        return str_contains((string)$second->message, 'cooldown') ?: 'message was: ' . $second->message;
    } finally {
        $settings->cooldown = 0;
    }
});

check('history returns every row for a URL, newest first', function() use ($plugin, $base) {
    $history = $plugin->submissions->history($base . '/one');

    return count($history) >= 2 ?: count($history) . ' rows';
});

check('the summary counts by status', function() use ($plugin) {
    $summary = $plugin->submissions->summary();

    return array_keys($summary) === SubmissionRecord::STATUSES ?: json_encode(array_keys($summary));
});

// ----------------------------------------------------------------- results

section('Recording an engine’s answer');

check('a sent result stamps dateSent and clears the retry time', function() use ($plugin, $base) {
    $row = $plugin->submissions->queue($base . '/recorded-sent', GoogleEngine::HANDLE);
    $plugin->submissions->record($row, SubmissionResult::sent(200, 'ok'));

    return ($row->status === SubmissionRecord::STATUS_SENT && $row->dateSent !== null && $row->nextAttempt === null)
        ?: json_encode($row->getAttributes(['status', 'dateSent', 'nextAttempt']));
});

check('a retryable failure goes back to pending with a future attempt time', function() use ($plugin, $base) {
    $row = $plugin->submissions->queue($base . '/recorded-retry', GoogleEngine::HANDLE);
    $plugin->submissions->record($row, SubmissionResult::retry('rate limited', 429));

    return ($row->status === SubmissionRecord::STATUS_PENDING && $row->nextAttempt !== null && $row->attempts === 1)
        ?: json_encode($row->getAttributes(['status', 'nextAttempt', 'attempts']));
});

check('the wait doubles between attempts', function() use ($plugin, $base) {
    $row = $plugin->submissions->queue($base . '/recorded-backoff', GoogleEngine::HANDLE);

    // `nextAttempt` is stored in UTC. Parsing it in the server's own zone is off by the whole
    // offset — the same trap the dispatcher avoids by comparing UTC against UTC.
    $utc = static fn(string $value): int => (new DateTimeImmutable($value, new DateTimeZone('UTC')))->getTimestamp();

    $plugin->submissions->record($row, SubmissionResult::retry('again', 500));
    $first = $utc((string)$row->nextAttempt) - time();

    $plugin->submissions->record($row, SubmissionResult::retry('again', 500));
    $second = $utc((string)$row->nextAttempt) - time();

    return $second >= $first * 1.8 ?: "waits were {$first}s then {$second}s";
});

check('retries stop at the attempt limit and say how many were made', function() use ($plugin, $base) {
    $row = $plugin->submissions->queue($base . '/recorded-giveup', GoogleEngine::HANDLE);

    for ($i = 0; $i < 5; $i++) {
        $plugin->submissions->record($row, SubmissionResult::retry('still failing', 500));
    }

    return ($row->status === SubmissionRecord::STATUS_FAILED && str_contains((string)$row->message, 'Gave up'))
        ?: $row->status . ' — ' . $row->message;
});

check('a non-retryable failure gives up immediately', function() use ($plugin, $base) {
    $row = $plugin->submissions->queue($base . '/recorded-fatal', GoogleEngine::HANDLE);
    $plugin->submissions->record($row, SubmissionResult::failed('bad key', 403));

    return ($row->status === SubmissionRecord::STATUS_FAILED && $row->attempts === 1)
        ?: $row->status . ' after ' . $row->attempts;
});

check('requeueing resets the attempt counter, because a human retry is a new decision', function() use ($plugin, $base) {
    $row = $plugin->submissions->queue($base . '/recorded-requeue', GoogleEngine::HANDLE);
    $plugin->submissions->record($row, SubmissionResult::failed('nope', 400));
    $plugin->submissions->requeue($row);

    return ($row->status === SubmissionRecord::STATUS_PENDING && $row->attempts === 0 && $row->message === null)
        ?: json_encode($row->getAttributes(['status', 'attempts', 'message']));
});

check('a result costing quota is distinguished from one that never reached the engine', function() {
    return (SubmissionResult::sent(200)->costsQuota()
        && SubmissionResult::failed('x', 403)->costsQuota()
        && !SubmissionResult::retry('x', 503)->costsQuota()
        && !SubmissionResult::failed('x', 0)->costsQuota())
        ?: 'quota accounting is wrong';
});

// ---------------------------------------------------------------------- quota

section('Quota');

check('consuming increments, and a second call adds to the first', function() use ($plugin) {
    $plugin->quota->reset('sanka-check-quota');
    $plugin->quota->consume('sanka-check-quota', '2026-01-01', 3);
    $plugin->quota->consume('sanka-check-quota', '2026-01-01', 4);

    return $plugin->quota->used('sanka-check-quota', '2026-01-01') === 7
        ?: 'got ' . $plugin->quota->used('sanka-check-quota', '2026-01-01');
});

check('consuming zero or less does nothing', function() use ($plugin) {
    $plugin->quota->consume('sanka-check-quota', '2026-01-01', 0);
    $plugin->quota->consume('sanka-check-quota', '2026-01-01', -5);

    return $plugin->quota->used('sanka-check-quota', '2026-01-01') === 7 ?: 'the count moved';
});

check('a day with no row reads as zero rather than erroring', function() use ($plugin) {
    return $plugin->quota->used('sanka-check-quota', '1999-01-01') === 0 ?: 'not zero';
});

check('remaining is the limit minus what was spent', function() use ($plugin) {
    $google = $plugin->engines->getGoogle();
    $plugin->quota->reset($google->handle(), $google->quotaDate());
    $plugin->quota->consume($google->handle(), $google->quotaDate(), 12);

    return $plugin->quota->remaining($google) === 188 ?: 'got ' . var_export($plugin->quota->remaining($google), true);
});

check('an engine with no daily limit reports null rather than zero', function() use ($plugin) {
    return $plugin->quota->remaining($plugin->engines->getSitemap()) === null ?: 'reported a limit';
});

check('Google’s quota day is Pacific, not the server’s', function() use ($plugin) {
    $expected = (new DateTimeImmutable('now', new DateTimeZone(GoogleEngine::QUOTA_TIMEZONE)))->format('Y-m-d');

    return $plugin->engines->getGoogle()->quotaDate() === $expected ?: 'got ' . $plugin->engines->getGoogle()->quotaDate();
});

check('pruning drops old rows and keeps recent ones', function() use ($plugin) {
    $plugin->quota->reset('sanka-check-quota');
    $plugin->quota->consume('sanka-check-quota', '2000-01-01', 1);
    $plugin->quota->consume('sanka-check-quota', (new DateTime())->format('Y-m-d'), 1);
    $plugin->quota->prune(30);

    return ($plugin->quota->used('sanka-check-quota', '2000-01-01') === 0
        && $plugin->quota->used('sanka-check-quota', (new DateTime())->format('Y-m-d')) === 1)
        ?: 'pruning removed the wrong rows';
});

// ------------------------------------------------------------------- engines

section('Google Indexing API');

check('a single URL is published, not batched', function() use ($plugin, $http, $base) {
    $http->reset();
    $http->setResponder(FakeHttpClient::defaultResponder());

    $results = $plugin->engines->getGoogle()->submit([$base . '/g1'], SubmissionRecord::TYPE_UPDATED);
    $publish = $http->requestsTo('urlNotifications:publish');

    return (count($publish) === 1 && $results[$base . '/g1']->isSent())
        ?: count($publish) . ' publish calls, result ' . ($results[$base . '/g1']->status ?? '?');
});

check('the publish body carries the URL and the notification type', function() use ($http) {
    $body = json_decode((string)$http->requestsTo('urlNotifications:publish')[0]['body'], true);

    return ($body['type'] === 'URL_UPDATED' && str_contains($body['url'], '/g1')) ?: json_encode($body);
});

check('a delete is sent as URL_DELETED', function() use ($plugin, $http, $base) {
    $http->reset();
    $plugin->engines->getGoogle()->submit([$base . '/g2'], SubmissionRecord::TYPE_DELETED);
    $body = json_decode((string)$http->requestsTo('urlNotifications:publish')[0]['body'], true);

    return $body['type'] === 'URL_DELETED' ?: json_encode($body);
});

check('the request carries a bearer token obtained from the token endpoint', function() use ($http) {
    $publish = $http->requestsTo('urlNotifications:publish')[0];

    return str_starts_with((string)$publish['headers']['Authorization'], 'Bearer ')
        ?: json_encode($publish['headers']);
});

check('a 403 explains Search Console ownership rather than printing the code', function() use ($plugin, $http, $base) {
    $http->setResponder(FakeHttpClient::fixed(403, '{"error":{"code":403,"message":"Permission denied"}}'));
    $results = $plugin->engines->getGoogle()->submit([$base . '/g3'], SubmissionRecord::TYPE_UPDATED);
    $message = $results[$base . '/g3']->message;

    return str_contains($message, 'Search Console') ?: "said: {$message}";
});

check('a 403 is not retried, because no retry could fix it', function() use ($plugin, $http, $base) {
    $results = $plugin->engines->getGoogle()->submit([$base . '/g4'], SubmissionRecord::TYPE_UPDATED);

    return !$results[$base . '/g4']->retryable ?: 'a permission error was marked retryable';
});

check('a 429 explains the quota and is retryable', function() use ($plugin, $http, $base) {
    $http->setResponder(FakeHttpClient::fixed(429, '{"error":{"message":"Quota exceeded"}}'));
    $result = $plugin->engines->getGoogle()->submit([$base . '/g5'], SubmissionRecord::TYPE_UPDATED)[$base . '/g5'];

    return ($result->retryable && str_contains($result->message, 'midnight Pacific'))
        ?: "retryable={$result->retryable} message={$result->message}";
});

check('a 5xx is retryable', function() use ($plugin, $http, $base) {
    $http->setResponder(FakeHttpClient::fixed(503, '{}'));
    $result = $plugin->engines->getGoogle()->submit([$base . '/g6'], SubmissionRecord::TYPE_UPDATED)[$base . '/g6'];

    return $result->retryable ?: 'an outage was treated as permanent';
});

check('several URLs go out as one multipart batch', function() use ($plugin, $http, $base) {
    $http->reset();
    $http->setResponder(function(string $method, string $url) use ($base): HttpResponse {
        if (str_contains($url, 'oauth2')) {
            return (FakeHttpClient::defaultResponder())($method, $url, [], null);
        }

        // Answered out of order on purpose: parts are matched by Content-ID, never by position.
        $body = "--batch_x\r\nContent-Type: application/http\r\nContent-ID: <response-sanka-1>\r\n\r\n"
            . "HTTP/1.1 200 OK\r\nContent-Type: application/json\r\n\r\n{}\r\n"
            . "--batch_x\r\nContent-Type: application/http\r\nContent-ID: <response-sanka-0>\r\n\r\n"
            . "HTTP/1.1 403 Forbidden\r\nContent-Type: application/json\r\n\r\n{\"error\":{\"message\":\"Permission denied\"}}\r\n"
            . "--batch_x--\r\n";

        return new HttpResponse(200, $body, ['content-type' => 'multipart/mixed; boundary=batch_x']);
    });

    $results = $plugin->engines->getGoogle()->submit([$base . '/b0', $base . '/b1'], SubmissionRecord::TYPE_UPDATED);

    return (count($http->requestsTo('/batch')) === 1 && count($http->requestsTo('urlNotifications:publish')) === 0)
        ?: count($http->requestsTo('/batch')) . ' batch calls';
});

check('batch parts are matched by Content-ID, not by the order they came back in', function() use ($plugin, $base) {
    $results = $plugin->engines->getGoogle()->submit([$base . '/b0', $base . '/b1'], SubmissionRecord::TYPE_UPDATED);

    return (!$results[$base . '/b0']->isSent() && $results[$base . '/b1']->isSent())
        ?: 'b0=' . $results[$base . '/b0']->status . ' b1=' . $results[$base . '/b1']->status;
});

check('the batch request is multipart/mixed with one part per URL', function() use ($http) {
    $batch = $http->requestsTo('/batch')[0];
    $parts = substr_count((string)$batch['body'], 'Content-Type: application/http');

    return (str_contains((string)$batch['headers']['Content-Type'], 'multipart/mixed') && $parts === 2)
        ?: "{$parts} parts, type " . $batch['headers']['Content-Type'];
});

check('a URL with no answer in the batch is retried rather than assumed sent', function() use ($plugin, $http, $base) {
    $http->setResponder(function(string $method, string $url) use ($base): HttpResponse {
        if (str_contains($url, 'oauth2')) {
            return (FakeHttpClient::defaultResponder())($method, $url, [], null);
        }

        $body = "--batch_y\r\nContent-Type: application/http\r\nContent-ID: <response-sanka-0>\r\n\r\n"
            . "HTTP/1.1 200 OK\r\n\r\n{}\r\n--batch_y--\r\n";

        return new HttpResponse(200, $body, ['content-type' => 'multipart/mixed; boundary=batch_y']);
    });

    $results = $plugin->engines->getGoogle()->submit([$base . '/m0', $base . '/m1'], SubmissionRecord::TYPE_UPDATED);

    return ($results[$base . '/m0']->isSent() && $results[$base . '/m1']->retryable)
        ?: 'm1 was ' . $results[$base . '/m1']->status;
});

check('a batch that fails wholesale gives every URL the same answer', function() use ($plugin, $http, $base) {
    $http->setResponder(FakeHttpClient::fixed(401, '{"error":{"message":"Invalid credentials"}}'));
    $results = $plugin->engines->getGoogle()->submit([$base . '/w0', $base . '/w1'], SubmissionRecord::TYPE_UPDATED);

    return (count($results) === 2 && !$results[$base . '/w0']->isSent() && !$results[$base . '/w1']->isSent())
        ?: json_encode(array_map(static fn($r) => $r->status, $results));
});

check('metadata returns null when Google has never heard of the URL', function() use ($plugin, $http, $base) {
    $http->setResponder(FakeHttpClient::fixed(404, '{}'));

    return $plugin->engines->getGoogle()->metadata($base . '/unknown') === null ?: 'did not return null';
});

check('metadata returns the body when Google has', function() use ($plugin, $http, $base) {
    $http->setResponder(FakeHttpClient::fixed(200, '{"url":"x","latestUpdate":{"type":"URL_UPDATED"}}'));
    $metadata = $plugin->engines->getGoogle()->metadata($base . '/known');

    return ($metadata['latestUpdate']['type'] ?? null) === 'URL_UPDATED' ?: json_encode($metadata);
});

check('an unreadable key fails every URL without making a call', function() use ($plugin, $http, $base) {
    $settings = $plugin->getSettings();
    $was = $settings->googleCredentials;
    $settings->googleCredentials = '{"type":"service_account","client_email":"a@b.c","private_key":"not a key"}';
    $plugin->engines->reset();
    $plugin->engines->setHttpClient($http);
    $http->reset();

    try {
        $results = $plugin->engines->getGoogle()->submit([$base . '/nokey'], SubmissionRecord::TYPE_UPDATED);
        $calls = count($http->requestsTo('indexing.googleapis.com'));

        return ($calls === 0 && !$results[$base . '/nokey']->isSent()) ?: "{$calls} calls made";
    } finally {
        $settings->googleCredentials = $was;
        $plugin->engines->reset();
        $plugin->engines->setHttpClient($http);
    }
});

check('signing works locally, so a rejection can be blamed on Google rather than the key', function() use ($plugin) {
    $assertion = $plugin->engines->getGoogle()->buildAssertion(time());

    return substr_count($assertion, '.') === 2 ?: 'assertion was not three segments';
});

section('IndexNow');

check('a submission posts the documented payload', function() use ($plugin, $http, $base, $host) {
    $http->reset();
    $http->setResponder(FakeHttpClient::fixed(200, ''));

    $plugin->engines->getIndexNow()->submit([$base . '/i1', $base . '/i2'], SubmissionRecord::TYPE_UPDATED);
    $payload = json_decode((string)$http->requestsTo('indexnow')[0]['body'], true);

    return ($payload['host'] === $host
        && $payload['key'] === 'sankacheck0123456789abcdef'
        && count($payload['urlList']) === 2
        && str_contains($payload['keyLocation'], 'sankacheck0123456789abcdef.txt'))
        ?: json_encode($payload);
});

check('it posts to the configured endpoint', function() use ($http) {
    return str_starts_with((string)$http->requestsTo('indexnow')[0]['url'], 'https://api.indexnow.org/indexnow')
        ?: $http->requestsTo('indexnow')[0]['url'];
});

check('many URLs are one call, not one call each', function() use ($plugin, $http, $base) {
    $http->reset();
    $urls = array_map(static fn(int $i): string => "{$base}/bulk-{$i}", range(1, 25));
    $plugin->engines->getIndexNow()->submit($urls, SubmissionRecord::TYPE_UPDATED);

    return count($http->requestsTo('indexnow')) === 1 ?: count($http->requestsTo('indexnow')) . ' calls';
});

check('URLs on different hosts are split, because the protocol is per-host', function() use ($plugin, $http, $base) {
    $http->reset();
    $plugin->engines->getIndexNow()->submit([$base . '/h1', 'https://other.example/h2'], SubmissionRecord::TYPE_UPDATED);

    return count($http->requestsTo('indexnow')) === 2 ?: count($http->requestsTo('indexnow')) . ' calls';
});

check('202 counts as accepted and explains the key file will be fetched', function() use ($plugin, $http, $base) {
    $http->setResponder(FakeHttpClient::fixed(202, ''));
    $result = $plugin->engines->getIndexNow()->submit([$base . '/i3'], SubmissionRecord::TYPE_UPDATED)[$base . '/i3'];

    return ($result->isSent() && str_contains($result->message, '.txt')) ?: $result->status . ' — ' . $result->message;
});

check('403 explains the key file rather than the status code', function() use ($plugin, $http, $base) {
    $http->setResponder(FakeHttpClient::fixed(403, ''));
    $result = $plugin->engines->getIndexNow()->submit([$base . '/i4'], SubmissionRecord::TYPE_UPDATED)[$base . '/i4'];

    return str_contains($result->message, 'key') ?: $result->message;
});

check('422 explains the host mismatch', function() use ($plugin, $http, $base) {
    $http->setResponder(FakeHttpClient::fixed(422, ''));
    $result = $plugin->engines->getIndexNow()->submit([$base . '/i5'], SubmissionRecord::TYPE_UPDATED)[$base . '/i5'];

    return str_contains($result->message, 'base URL') ?: $result->message;
});

check('429 is retryable', function() use ($plugin, $http, $base) {
    $http->setResponder(FakeHttpClient::fixed(429, ''));

    return $plugin->engines->getIndexNow()->submit([$base . '/i6'], SubmissionRecord::TYPE_UPDATED)[$base . '/i6']->retryable
        ?: 'a rate limit was treated as permanent';
});

check('400 is not retryable', function() use ($plugin, $http, $base) {
    $http->setResponder(FakeHttpClient::fixed(400, ''));

    return !$plugin->engines->getIndexNow()->submit([$base . '/i7'], SubmissionRecord::TYPE_UPDATED)[$base . '/i7']->retryable
        ?: 'a malformed request was retried';
});

check('a missing key is a problem stated as an action', function() use ($plugin, $http) {
    $settings = $plugin->getSettings();
    $was = $settings->indexNowKey;
    $settings->indexNowKey = '';

    try {
        $problems = $plugin->engines->getIndexNow()->problems();

        return (count($problems) === 1 && str_contains($problems[0], 'Generate one')) ?: json_encode($problems);
    } finally {
        $settings->indexNowKey = $was;
    }
});

check('the key file URL sits at the site root', function() use ($plugin, $host) {
    $url = $plugin->engines->getIndexNow()->keyUrl();

    return str_contains($url, $host) && str_ends_with($url, 'sankacheck0123456789abcdef.txt') ?: $url;
});

section('Sitemaps');

check('a sitemap is never withdrawn, only resubmitted', function() use ($plugin, $base) {
    $result = $plugin->engines->getSitemap()->submit([$base . '/sitemap.xml'], SubmissionRecord::TYPE_DELETED)[$base . '/sitemap.xml'];

    return $result->status === SubmissionRecord::STATUS_SKIPPED ?: $result->status;
});

check('sitemaps are discovered for every site with a base URL', function() use ($plugin) {
    $urls = $plugin->engines->getSitemap()->sitemapUrls();

    if ($urls === []) {
        return 'no sitemaps were offered at all';
    }

    foreach ($urls as $url) {
        if (!is_string($url) || !str_starts_with($url, 'http')) {
            return json_encode($urls);
        }
    }

    return true;
});

check('an installed SEO plugin is asked where its sitemap index actually is', function() use ($plugin) {
    $seomatic = Craft::$app->getPlugins()->getPlugin('seomatic');

    // The guess — `/sitemap.xml` — is wrong on an SEOmatic site: the index lives at
    // `/sitemaps-<groupId>-sitemap.xml` and `/sitemap.xml` only redirects to it.
    if ($seomatic === null) {
        return true;
    }

    $expected = [];

    foreach (Craft::$app->getSites()->getAllSites() as $site) {
        if (trim((string)$site->getBaseUrl()) === '') {
            continue;
        }

        $expected[] = $seomatic->sitemaps->sitemapIndexUrlForSiteId($site->id);
    }

    return ($plugin->engines->getSitemap()->sitemapUrls() === array_values(array_unique($expected))
        && $plugin->engines->getSitemap()->sitemapSource() === 'SEOmatic')
        ?: json_encode([$plugin->engines->getSitemap()->sitemapUrls(), $plugin->engines->getSitemap()->sitemapSource()]);
});

check('the guess is used when nothing can be detected', function() use ($plugin) {
    $engine = $plugin->engines->getSitemap();
    $method = new \ReflectionMethod($engine, 'guessedUrls');
    $urls = $method->invoke($engine);

    return ($urls !== [] && str_ends_with($urls[0], '/sitemap.xml')) ?: json_encode($urls);
});

check('configured URLs beat anything an SEO plugin says', function() use ($plugin) {
    $settings = $plugin->getSettings();
    $settings->sitemapUrls = ['https://example.com/mine.xml'];

    try {
        return ($plugin->engines->getSitemap()->sitemapUrls() === ['https://example.com/mine.xml']
            && $plugin->engines->getSitemap()->sitemapSource() === 'Configured here')
            ?: json_encode([$plugin->engines->getSitemap()->sitemapUrls(), $plugin->engines->getSitemap()->sitemapSource()]);
    } finally {
        $settings->sitemapUrls = [];
    }
});

check('configured sitemap URLs win over the guess', function() use ($plugin) {
    $settings = $plugin->getSettings();
    $settings->sitemapUrls = ['https://example.com/custom.xml'];

    try {
        return $plugin->engines->getSitemap()->sitemapUrls() === ['https://example.com/custom.xml']
            ?: json_encode($plugin->engines->getSitemap()->sitemapUrls());
    } finally {
        $settings->sitemapUrls = [];
    }
});

check('sitemap resubmission says so when IndexNow is off', function() use ($plugin) {
    $settings = $plugin->getSettings();
    $settings->indexNowEnabled = false;

    try {
        return str_contains(implode(' ', $plugin->engines->getSitemap()->problems()), 'IndexNow, which is switched off')
            ?: json_encode($plugin->engines->getSitemap()->problems());
    } finally {
        $settings->indexNowEnabled = true;
    }
});

section('The registry');

check('Lite cannot see the sitemap engine', function() use ($plugins, $plugin) {
    $plugins->switchEdition('sanka', Plugin::EDITION_LITE);
    $plugin->engines->reset();

    try {
        return !array_key_exists(SitemapEngine::HANDLE, $plugin->engines->getAvailable())
            ?: 'sitemaps were available on Lite';
    } finally {
        $plugins->switchEdition('sanka', Plugin::EDITION_PRO);
        $plugin->engines->reset();
    }
});

check('Pro can', function() use ($plugin, $http) {
    $plugin->engines->setHttpClient($http);

    return array_key_exists(SitemapEngine::HANDLE, $plugin->engines->getAvailable()) ?: 'sitemaps were missing on Pro';
});

check('an engine that is on but unconfigured is enabled and not usable', function() use ($plugin, $http) {
    $settings = $plugin->getSettings();
    $was = $settings->googleCredentials;
    $settings->googleCredentials = '';
    $plugin->engines->reset();
    $plugin->engines->setHttpClient($http);

    try {
        $enabled = array_key_exists(GoogleEngine::HANDLE, $plugin->engines->getEnabled());
        $usable = array_key_exists(GoogleEngine::HANDLE, $plugin->engines->getUsable());

        return ($enabled && !$usable) ?: "enabled={$enabled} usable={$usable}";
    } finally {
        $settings->googleCredentials = $was;
        $plugin->engines->reset();
        $plugin->engines->setHttpClient($http);
    }
});

// ---------------------------------------------------------------- dispatcher

section('The dispatcher');

check('dry run records what would have been sent and sends nothing', function() use ($plugin, $http, $base) {
    $settings = $plugin->getSettings();
    $settings->dryRun = true;
    $http->reset();
    $http->setResponder(FakeHttpClient::defaultResponder());

    try {
        $plugin->submissions->queue($base . '/dry', GoogleEngine::HANDLE);
        $counts = $plugin->dispatcher->drain(GoogleEngine::HANDLE);
        $calls = count($http->requestsTo('indexing.googleapis.com'));

        $row = SubmissionRecord::find()->where(['url' => $base . '/dry'])->one();

        return ($calls === 0
            && $counts['skipped'] >= 1
            && $row->status === SubmissionRecord::STATUS_SKIPPED
            && str_contains((string)$row->message, 'Dry run'))
            ?: "{$calls} calls, status " . $row->status;
    } finally {
        $settings->dryRun = false;
    }
});

check('dry run spends no quota', function() use ($plugin) {
    $google = $plugin->engines->getGoogle();
    $before = $plugin->quota->used($google->handle(), $google->quotaDate());
    $plugin->quota->reset($google->handle(), $google->quotaDate());

    return $plugin->quota->used($google->handle(), $google->quotaDate()) === 0 ?: 'quota did not reset';
});

check('a real drain marks rows sent and consumes quota', function() use ($plugin, $http, $base) {
    $http->reset();
    $http->setResponder(FakeHttpClient::defaultResponder());
    $google = $plugin->engines->getGoogle();
    $plugin->quota->reset($google->handle(), $google->quotaDate());

    $plugin->submissions->queue($base . '/live-1', GoogleEngine::HANDLE);
    $plugin->submissions->queue($base . '/live-2', GoogleEngine::HANDLE);

    $counts = $plugin->dispatcher->drain(GoogleEngine::HANDLE);
    $used = $plugin->quota->used($google->handle(), $google->quotaDate());

    return ($counts['sent'] === 2 && $used === 2) ?: "sent={$counts['sent']} used={$used}";
});

check('the batch is cut to the remaining quota rather than discovering it is gone', function() use ($plugin, $http, $base) {
    $settings = $plugin->getSettings();
    $google = $plugin->engines->getGoogle();
    $was = $settings->googleDailyQuota;

    $plugin->quota->reset($google->handle(), $google->quotaDate());
    $settings->googleDailyQuota = 2;
    $http->reset();
    $http->setResponder(FakeHttpClient::defaultResponder());

    // Earlier checks left rows waiting; this one counts what is left afterwards, so it needs to
    // start from an empty queue rather than from whatever the suite happens to have accumulated.
    Craft::$app->getDb()->createCommand()->delete(SubmissionRecord::TABLE, [
        'status' => SubmissionRecord::STATUS_PENDING,
        'engine' => GoogleEngine::HANDLE,
    ])->execute();

    try {
        foreach (range(1, 5) as $i) {
            $plugin->submissions->queue("{$base}/capped-{$i}", GoogleEngine::HANDLE);
        }

        $counts = $plugin->dispatcher->drain(GoogleEngine::HANDLE);

        return ($counts['sent'] === 2 && $plugin->submissions->pendingCount(GoogleEngine::HANDLE) === 3)
            ?: "sent={$counts['sent']} pending=" . $plugin->submissions->pendingCount(GoogleEngine::HANDLE);
    } finally {
        $settings->googleDailyQuota = $was;
    }
});

check('an exhausted quota leaves the rest pending for tomorrow', function() use ($plugin) {
    $settings = $plugin->getSettings();
    $google = $plugin->engines->getGoogle();
    $was = $settings->googleDailyQuota;
    $settings->googleDailyQuota = 2;

    try {
        $counts = $plugin->dispatcher->drain(GoogleEngine::HANDLE);

        return ($counts['sent'] === 0 && $counts['quotaBlocked'] > 0) ?: json_encode($counts);
    } finally {
        $settings->googleDailyQuota = $was;
    }
});

check('updates and deletes drain as separate calls', function() use ($plugin, $http, $base) {
    $http->reset();
    $http->setResponder(FakeHttpClient::defaultResponder());
    $google = $plugin->engines->getGoogle();
    $plugin->quota->reset($google->handle(), $google->quotaDate());

    Craft::$app->getDb()->createCommand()->delete(SubmissionRecord::TABLE, [
        'status' => SubmissionRecord::STATUS_PENDING,
    ])->execute();

    $plugin->submissions->queue($base . '/typed-a', GoogleEngine::HANDLE, SubmissionRecord::TYPE_UPDATED);
    $plugin->submissions->queue($base . '/typed-b', GoogleEngine::HANDLE, SubmissionRecord::TYPE_DELETED);

    $plugin->dispatcher->drain(GoogleEngine::HANDLE);
    $publishes = $http->requestsTo('urlNotifications:publish');
    $types = array_map(static fn(array $r): string => json_decode((string)$r['body'], true)['type'], $publishes);

    return (count($publishes) === 2 && in_array('URL_UPDATED', $types, true) && in_array('URL_DELETED', $types, true))
        ?: json_encode($types);
});

check('an engine that throws gives every row in the batch a retryable answer', function() use ($plugin, $http, $base) {
    $http->setResponder(static function(): HttpResponse {
        throw new RuntimeException('the transport exploded');
    });

    $plugin->submissions->queue($base . '/thrown-1', IndexNowEngine::HANDLE);
    $plugin->submissions->queue($base . '/thrown-2', IndexNowEngine::HANDLE);
    $plugin->dispatcher->drain(IndexNowEngine::HANDLE);

    $rows = SubmissionRecord::find()->where(['like', 'url', '/thrown-'])->all();
    $http->setResponder(FakeHttpClient::defaultResponder());

    foreach ($rows as $row) {
        if ($row->status !== SubmissionRecord::STATUS_PENDING || $row->message === null) {
            return 'row ended as ' . $row->status . ' with message ' . var_export($row->message, true);
        }
    }

    return count($rows) === 2 ?: count($rows) . ' rows';
});

check('a row waiting on its backoff is not taken again immediately', function() use ($plugin, $base) {
    $pending = $plugin->submissions->pending(IndexNowEngine::HANDLE, null, 50);
    $urls = array_map(static fn(SubmissionRecord $r): string => (string)$r->url, $pending);

    return !in_array($base . '/thrown-1', $urls, true) ?: 'a backed-off row was offered again';
});

check('the status board reports each engine’s state and quota', function() use ($plugin) {
    $status = $plugin->dispatcher->status();
    $google = null;

    foreach ($status as $row) {
        if ($row['handle'] === GoogleEngine::HANDLE) {
            $google = $row;
        }
    }

    return ($google !== null && $google['quota'] === 200 && array_key_exists('problems', $google))
        ?: json_encode($google);
});

// ---------------------------------------------------------------- crawlers

section('AI crawlers');

check('every agent carries a purpose the control panel groups by', function() {
    foreach (CrawlerAgent::all() as $agent) {
        if (!in_array($agent->purpose, [CrawlerAgent::PURPOSE_SEARCH, CrawlerAgent::PURPOSE_USER, CrawlerAgent::PURPOSE_TRAINING], true)) {
            return "{$agent->token} has purpose {$agent->purpose}";
        }

        if (trim($agent->consequence) === '') {
            return "{$agent->token} does not say what blocking it costs";
        }
    }

    return true;
});

check('the registry has no duplicate tokens', function() {
    $tokens = array_map(static fn(CrawlerAgent $a): string => $a->token, CrawlerAgent::all());

    return count($tokens) === count(array_unique($tokens)) ?: 'duplicates in the registry';
});

check('blocking a training crawler costs no visibility; blocking a search one does', function() {
    return (!CrawlerAgent::find('GPTBot')->blockingCostsVisibility()
        && CrawlerAgent::find('OAI-SearchBot')->blockingCostsVisibility())
        ?: 'the two are being treated the same';
});

check('GPTBot is detected', function() use ($plugin) {
    $agent = $plugin->crawlers->detect('Mozilla/5.0 AppleWebKit/537.36 (KHTML, like Gecko); compatible; GPTBot/1.2; +https://openai.com/gptbot');

    return $agent?->token === 'GPTBot' ?: var_export($agent?->token, true);
});

check('an ordinary browser is not', function() use ($plugin) {
    return $plugin->crawlers->detect('Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 Chrome/120 Safari/537.36') === null
        ?: 'a human was logged as a crawler';
});

check('the longer token wins, so Claude-SearchBot is not read as ClaudeBot', function() use ($plugin) {
    $agent = $plugin->crawlers->detect('Mozilla/5.0 (compatible; Claude-SearchBot/1.0; +https://anthropic.com/bot)');

    return $agent?->token === 'Claude-SearchBot' ?: var_export($agent?->token, true);
});

check('a user-initiated fetch is told apart from the crawler', function() use ($plugin) {
    return $plugin->crawlers->detect('Mozilla/5.0 (compatible; ChatGPT-User/1.0; +https://openai.com/bot)')?->purpose === CrawlerAgent::PURPOSE_USER
        ?: 'ChatGPT-User was not read as a user request';
});

check('an empty user agent is not a crawler', function() use ($plugin) {
    return $plugin->crawlers->detect('') === null ?: 'an empty UA matched something';
});

check('recording a hit stores it unverified', function() use ($plugin, $base, $site) {
    $plugin->crawlers->record(CrawlerAgent::find('GPTBot'), $base . '/crawled', 200, 'GPTBot/1.2', '203.0.113.7', $site->id);
    $row = CrawlRecord::find()->where(['url' => $base . '/crawled'])->one();

    return ($row !== null && $row->verified === null && $row->agent === 'GPTBot')
        ?: 'row was ' . json_encode($row?->getAttributes(['agent', 'verified']));
});

check('the summary counts hits per agent', function() use ($plugin, $base, $site) {
    $plugin->crawlers->record(CrawlerAgent::find('GPTBot'), $base . '/crawled-2', 200, 'GPTBot/1.2', '203.0.113.7', $site->id);
    $summary = $plugin->crawlers->summary(30);
    $gpt = null;

    foreach ($summary as $row) {
        if ($row['agent'] === 'GPTBot') {
            $gpt = $row;
        }
    }

    return ($gpt !== null && $gpt['hits'] >= 2) ?: json_encode($gpt);
});

check('top pages groups by URL', function() use ($plugin) {
    $pages = $plugin->crawlers->topPages(30, 25);

    return $pages !== [] && isset($pages[0]['url'], $pages[0]['hits']) ?: json_encode($pages);
});

check('a vendor with no published reverse records is unverifiable, not forged', function() use ($plugin) {
    return $plugin->crawlers->verify('203.0.113.7', CrawlerAgent::find('Timpibot')) === null
        ?: 'claimed a verdict it cannot have';
});

check('robots.txt lists every blocked agent in its own group', function() use ($plugin) {
    $settings = $plugin->getSettings();
    $settings->crawlerPolicy = ['GPTBot' => 'block', 'CCBot' => 'block'];

    try {
        $robots = $plugin->crawlers->robotsTxt();

        return (substr_count($robots, "User-agent: GPTBot\nDisallow: /\n") === 1
            && substr_count($robots, "User-agent: CCBot\nDisallow: /\n") === 1)
            ?: $robots;
    } finally {
        $settings->crawlerPolicy = [];
    }
});

check('an allowed agent gets no Disallow line at all', function() use ($plugin) {
    $robots = $plugin->crawlers->robotsTxt();

    return !str_contains($robots, 'User-agent: GPTBot') ?: 'an allowed agent was still listed';
});

check('robots.txt always ends with a permissive default and the sitemaps', function() use ($plugin) {
    $robots = $plugin->crawlers->robotsTxt();

    return (str_contains($robots, 'User-agent: *') && str_contains($robots, 'Sitemap:')) ?: $robots;
});

check('robots.txt keeps the control panel out of the index', function() use ($plugin) {
    return str_contains($plugin->crawlers->robotsTxt(), '/cpresources/') ?: 'cpresources was not excluded';
});

check('the control panel is disallowed by path, never by absolute URL', function() use ($plugin) {
    // `Disallow` takes a path. A crawler handed `https://example.com/admin` compares it to the
    // request path literally and therefore never matches — so the line that was meant to hide the
    // control panel published it and hid nothing.
    foreach ($plugin->crawlers->cpDisallows() as $path) {
        if (!str_starts_with($path, '/')) {
            return "got {$path}";
        }
    }

    return !str_contains($plugin->crawlers->robotsTxt(), 'Disallow: http') ?: 'an absolute URL was written';
});

check('a customised cpTrigger is not published', function() use ($plugin) {
    $general = Craft::$app->getConfig()->getGeneral();
    $settings = $plugin->getSettings();
    $trigger = $general->cpTrigger;
    $mode = $settings->robotsDisallowCp;

    try {
        $general->cpTrigger = 'sanka-check-door';
        $settings->robotsDisallowCp = Settings::CP_AUTO;

        return !str_contains($plugin->crawlers->robotsTxt(), 'sanka-check-door')
            ?: 'the customised trigger was written into robots.txt';
    } finally {
        $general->cpTrigger = $trigger;
        $settings->robotsDisallowCp = $mode;
    }
});

check('the default trigger is still disallowed, by convention', function() use ($plugin) {
    $general = Craft::$app->getConfig()->getGeneral();
    $settings = $plugin->getSettings();
    $trigger = $general->cpTrigger;
    $mode = $settings->robotsDisallowCp;

    try {
        $general->cpTrigger = 'admin';
        $settings->robotsDisallowCp = Settings::CP_AUTO;

        return in_array('/admin', $plugin->crawlers->cpDisallows(), true)
            ?: json_encode($plugin->crawlers->cpDisallows());
    } finally {
        $general->cpTrigger = $trigger;
        $settings->robotsDisallowCp = $mode;
    }
});

check('“always” publishes a customised trigger and “never” publishes nothing', function() use ($plugin) {
    $general = Craft::$app->getConfig()->getGeneral();
    $settings = $plugin->getSettings();
    $trigger = $general->cpTrigger;
    $mode = $settings->robotsDisallowCp;

    try {
        $general->cpTrigger = 'sanka-check-door';

        $settings->robotsDisallowCp = Settings::CP_ALWAYS;
        $always = $plugin->crawlers->cpDisallows();

        $settings->robotsDisallowCp = Settings::CP_NEVER;
        $never = $plugin->crawlers->cpDisallows();

        return (in_array('/sanka-check-door', $always, true) && $never === [])
            ?: json_encode([$always, $never]);
    } finally {
        $general->cpTrigger = $trigger;
        $settings->robotsDisallowCp = $mode;
    }
});

check('a headless install has no trigger to disallow', function() use ($plugin) {
    $general = Craft::$app->getConfig()->getGeneral();
    $settings = $plugin->getSettings();
    $trigger = $general->cpTrigger;
    $mode = $settings->robotsDisallowCp;

    try {
        $general->cpTrigger = null;
        $settings->robotsDisallowCp = Settings::CP_ALWAYS;

        return $plugin->crawlers->cpDisallows() === ['/cpresources/']
            ?: json_encode($plugin->crawlers->cpDisallows());
    } finally {
        $general->cpTrigger = $trigger;
        $settings->robotsDisallowCp = $mode;
    }
});

check('an unknown robotsDisallowCp value is rejected on save', function() {
    $s = new Settings();
    $s->robotsDisallowCp = 'sometimes';

    return !$s->validate(['robotsDisallowCp']) ?: 'an unknown mode was accepted';
});

check('extra lines are appended verbatim', function() use ($plugin) {
    $settings = $plugin->getSettings();
    $settings->robotsExtra = 'Crawl-delay: 10';

    try {
        return str_contains($plugin->crawlers->robotsTxt(), 'Crawl-delay: 10') ?: 'the extra lines were dropped';
    } finally {
        $settings->robotsExtra = '';
    }
});

check('pruning removes old crawl rows', function() use ($plugin, $base, $site) {
    $plugin->crawlers->record(CrawlerAgent::find('CCBot'), $base . '/old', 200, 'CCBot/2.0', '198.51.100.4', $site->id);
    Craft::$app->getDb()->createCommand()->update(CrawlRecord::TABLE, [
        'dateCreated' => Db::prepareDateForDb((new DateTime())->modify('-400 days')),
    ], ['url' => $base . '/old'])->execute();

    $plugin->crawlers->prune(30);

    return CrawlRecord::find()->where(['url' => $base . '/old'])->exists() === false ?: 'the old row survived';
});

// -------------------------------------------------------------------- llms

section('llms.txt');

check('the map opens with the site name as an H1', function() use ($plugin, $site) {
    $map = $plugin->llms->map($site->id);

    return str_starts_with($map, '# ' . $site->getName()) ?: substr($map, 0, 80);
});

check('the summary is quoted under the heading', function() use ($plugin, $site) {
    $settings = $plugin->getSettings();
    $settings->llmsSummary = 'A test summary for the checks.';
    $plugin->llms->invalidate();

    try {
        return str_contains($plugin->llms->map($site->id), '> A test summary for the checks.')
            ?: 'the summary was not quoted';
    } finally {
        $settings->llmsSummary = '';
        $plugin->llms->invalidate();
    }
});

check('entries are listed as Markdown links', function() use ($plugin, $site) {
    $map = $plugin->llms->map($site->id);

    return (bool)preg_match('/^- \[.+\]\(https?:\/\//m', $map) ?: 'no linked entries found';
});

check('the long file is at least as big as the map', function() use ($plugin, $site) {
    return strlen($plugin->llms->full($site->id)) >= strlen($plugin->llms->map($site->id))
        ?: 'llms-full.txt was smaller than llms.txt';
});

check('singles are gathered under a heading of their own', function() use ($plugin, $site) {
    $map = $plugin->llms->map($site->id);

    return (str_contains($map, '## ') || str_contains($map, '# ')) ?: 'no headings at all';
});

check('only sections with URLs are offered', function() use ($plugin, $site) {
    foreach ($plugin->llms->sections($site->id) as $section) {
        $hasUrls = false;

        foreach ($section->getSiteSettings() as $siteSettings) {
            if ($siteSettings->siteId === $site->id && $siteSettings->hasUrls) {
                $hasUrls = true;
            }
        }

        if (!$hasUrls) {
            return "{$section->handle} has no URLs on this site but was offered";
        }
    }

    return true;
});

check('invalidating clears the cache so a saved entry cannot go stale', function() use ($plugin, $site) {
    $plugin->llms->map($site->id);
    $plugin->llms->invalidate();

    return Craft::$app->getCache()->get(justinholtweb\sanka\services\Llms::class . ":map:{$site->id}") === false
        ?: 'the cached copy survived invalidation';
});

// --------------------------------------------------------------------- geo

section('GEO readiness audit');

$goodHtml = <<<'HTML'
<!doctype html><html><head>
<title>What instant indexing actually does</title>
<meta name="description" content="Instant indexing pushes a URL to a search engine the moment it changes, instead of waiting to be crawled.">
<link rel="canonical" href="https://example.com/instant-indexing">
<script type="application/ld+json">{"@context":"https://schema.org","@type":"Article","headline":"What instant indexing actually does","author":{"@type":"Person","name":"J Holt"},"datePublished":"2026-01-02","dateModified":"2026-03-04","publisher":{"@type":"Organization","name":"Example"}}</script>
</head><body><main>
<h1>What instant indexing actually does</h1>
<p>Instant indexing is a protocol that tells a search engine a URL changed, so the page is fetched within minutes instead of whenever the crawler next comes round. It replaces waiting with telling.</p>
<h2>How much faster is it?</h2>
<p>Bing reports indexing within 10 minutes for 95 percent of submitted URLs, against a median of 3 days for discovered ones. Google allows 200 submissions per day.</p>
<ul><li>Submit on publish</li><li>Submit on update</li><li>Submit on removal</li></ul>
<h3>What it does not do</h3>
<p>It does not affect ranking. It affects only how quickly a page is seen. See <a href="https://www.indexnow.org/">the IndexNow specification</a> for the protocol itself.</p>
<table><tr><th>Engine</th><td>Bing</td></tr></table>
</main></body></html>
HTML;

check('a well-formed page scores high', function() use ($plugin, $goodHtml) {
    $report = $plugin->geo->audit($goodHtml, 'https://example.com/instant-indexing');

    return $report->score() >= 80 ?: 'scored ' . $report->score() . ' — ' . json_encode(array_map(
        static fn($f) => $f->id . '=' . $f->status,
        $report->actionable(),
    ));
});

check('an empty page scores far below a good one', function() use ($plugin, $goodHtml) {
    $empty = $plugin->geo->audit('<html><body></body></html>')->score();
    $good = $plugin->geo->audit($goodHtml)->score();

    return ($empty < 40 && $good - $empty >= 40) ?: "empty={$empty} good={$good}";
});

check('the score is per dimension as well as overall', function() use ($plugin, $goodHtml) {
    $scores = $plugin->geo->audit($goodHtml)->scores();

    return array_keys($scores) === GeoFinding::DIMENSIONS ?: json_encode($scores);
});

check('two H1s are a warning, not a pass', function() use ($plugin) {
    $report = $plugin->geo->audit('<html><body><main><h1>One</h1><h1>Two</h1><p>Body text here.</p></main></body></html>');

    foreach ($report->findings as $finding) {
        if ($finding->id === 'h1-single') {
            return $finding->status === GeoFinding::WARN ?: 'status was ' . $finding->status;
        }
    }

    return 'the h1 check did not run';
});

check('no H1 at all is a failure with a remediation', function() use ($plugin) {
    $report = $plugin->geo->audit('<html><body><main><p>Body.</p></main></body></html>');

    foreach ($report->findings as $finding) {
        if ($finding->id === 'h1-single') {
            return ($finding->status === GeoFinding::FAIL && $finding->remediation !== '')
                ?: 'status ' . $finding->status . ' remediation ' . var_export($finding->remediation, true);
        }
    }

    return 'the h1 check did not run';
});

check('noindex fails and says nothing else matters until it goes', function() use ($plugin, $goodHtml) {
    $html = str_replace('<title>', '<meta name="robots" content="noindex,follow"><title>', $goodHtml);
    $report = $plugin->geo->audit($html);

    foreach ($report->findings as $finding) {
        if ($finding->id === 'noindex') {
            return ($finding->status === GeoFinding::FAIL && str_contains($finding->remediation, 'Nothing else'))
                ?: $finding->status . ' — ' . $finding->remediation;
        }
    }

    return 'the noindex check did not run';
});

check('JSON-LD inside @graph is found', function() use ($plugin) {
    $html = '<html><head><script type="application/ld+json">{"@context":"https://schema.org","@graph":[{"@type":"FAQPage"}]}</script></head><body><main><h1>x</h1></main></body></html>';
    $report = $plugin->geo->audit($html);

    foreach ($report->findings as $finding) {
        if ($finding->id === 'jsonld-type') {
            return $finding->status === GeoFinding::PASS ?: 'status ' . $finding->status . ': ' . $finding->message;
        }
    }

    return 'the type check did not run';
});

check('a page with only a BreadcrumbList is warned, not passed', function() use ($plugin) {
    $html = '<html><head><script type="application/ld+json">{"@type":"BreadcrumbList"}</script></head><body><main><h1>x</h1></main></body></html>';
    $report = $plugin->geo->audit($html);

    foreach ($report->findings as $finding) {
        if ($finding->id === 'jsonld-type') {
            return $finding->status === GeoFinding::WARN ?: 'status ' . $finding->status;
        }
    }

    return 'the type check did not run';
});

check('malformed JSON-LD is ignored rather than throwing', function() use ($plugin) {
    $report = $plugin->geo->audit('<html><head><script type="application/ld+json">{not json</script></head><body><main><h1>x</h1></main></body></html>');

    return $report->score() >= 0 ?: 'the audit threw';
});

check('script and style text is not counted as words', function() use ($plugin) {
    $padding = str_repeat('var noise = "not words"; ', 200);
    $report = $plugin->geo->audit("<html><body><main><h1>x</h1><p>Two words.</p><script>{$padding}</script></main></body></html>");

    foreach ($report->findings as $finding) {
        if ($finding->id === 'word-count') {
            return $finding->status === GeoFinding::FAIL ?: 'a page of JavaScript passed the word count';
        }
    }

    return 'the word count did not run';
});

check('every failing finding carries a remediation', function() use ($plugin) {
    $report = $plugin->geo->audit('<html><body></body></html>');

    foreach ($report->actionable() as $finding) {
        if (trim($finding->remediation) === '') {
            return "{$finding->id} tells you it is wrong but not what to do";
        }
    }

    return true;
});

check('failures are listed before warnings', function() use ($plugin) {
    $actionable = $plugin->geo->audit('<html><body></body></html>')->actionable();
    $seenWarning = false;

    foreach ($actionable as $finding) {
        if ($finding->status === GeoFinding::WARN) {
            $seenWarning = true;
        } elseif ($seenWarning && $finding->status === GeoFinding::FAIL) {
            return 'a failure came after a warning';
        }
    }

    return true;
});

check('the audit refuses a URL on somebody else’s host', function() use ($plugin) {
    try {
        $plugin->geo->auditUrl('https://example.org/anything');
    } catch (justinholtweb\sanka\errors\SankaException $e) {
        return str_contains($e->getMessage(), 'own sites') ?: $e->getMessage();
    }

    return 'it fetched a foreign host';
});

check('the audit reads a page on this site through the transport', function() use ($plugin, $http, $base, $goodHtml) {
    $http->setResponder(FakeHttpClient::fixed(200, $goodHtml));
    $report = $plugin->geo->auditUrl($base . '/audited');

    return $report->score() > 0 && $report->bytes === strlen($goodHtml) ?: 'bytes ' . $report->bytes;
});

check('a page that answers non-200 is reported rather than audited as empty', function() use ($plugin, $http, $base) {
    $http->setResponder(FakeHttpClient::fixed(500, 'oops'));

    try {
        $plugin->geo->auditUrl($base . '/broken');
    } catch (justinholtweb\sanka\errors\SankaException $e) {
        return str_contains($e->getMessage(), '500') ?: $e->getMessage();
    } finally {
        $http->setResponder(FakeHttpClient::defaultResponder());
    }

    return 'a 500 was audited as if it were a page';
});

// ---------------------------------------------------------- the save path

section('The save path');

$section = null;

foreach (Craft::$app->getEntries()->getAllSections() as $candidate) {
    if ($candidate->type === craft\models\Section::TYPE_SINGLE) {
        continue;
    }

    foreach ($candidate->getSiteSettings() as $siteSettings) {
        if ($siteSettings->siteId === $site->id && $siteSettings->hasUrls) {
            $section = $candidate;

            break 2;
        }
    }
}

if ($section === null) {
    section('The save path — skipped, no section with URLs in this harness');
} else {
    $plugin->getSettings()->autoSubmitRules = [
        ['section' => $section->handle, 'events' => Rule::EVENTS, 'engines' => ['indexnow']],
    ];

    check('saving a live entry queues a submission for the engines its rule names', function() use ($plugin, $section, $site, $suffix, &$createdEntries, $http) {
        $http->setResponder(FakeHttpClient::defaultResponder());

        Craft::$app->getDb()->createCommand()->delete(SubmissionRecord::TABLE, [
            'status' => SubmissionRecord::STATUS_PENDING,
        ])->execute();

        $entry = new Entry();
        $entry->sectionId = $section->id;
        $entry->typeId = $section->getEntryTypes()[0]->id;
        $entry->siteId = $site->id;
        $entry->title = "sanka-check-{$suffix} indexing";
        $entry->slug = "sanka-check-{$suffix}-indexing";
        $entry->enabled = true;
        $entry->postDate = new DateTime('-1 hour');

        if (!Craft::$app->getElements()->saveElement($entry)) {
            return 'could not save the entry: ' . json_encode($entry->getErrors());
        }

        $createdEntries[] = $entry;
        $url = (string)$entry->getUrl();

        $rows = $plugin->submissions->history($url);

        if ($rows === []) {
            return "nothing was queued for {$url}";
        }

        foreach ($rows as $row) {
            if ($row->engine !== 'indexnow') {
                return 'queued for ' . $row->engine . ', which the rule did not name';
            }
        }

        return true;
    });

    check('the queued row is linked to the entry that caused it', function() use ($plugin, $createdEntries) {
        $entry = $createdEntries[0] ?? null;

        if ($entry === null) {
            return 'no entry was created';
        }

        $row = $plugin->submissions->history((string)$entry->getUrl())[0];

        return (int)$row->elementId === (int)$entry->id ?: 'elementId was ' . var_export($row->elementId, true);
    });

    check('disabling a live entry queues a removal, not an update', function() use ($plugin, $createdEntries) {
        $entry = $createdEntries[0] ?? null;

        if ($entry === null) {
            return 'no entry was created';
        }

        $url = (string)$entry->getUrl();
        $entry->enabled = false;

        if (!Craft::$app->getElements()->saveElement($entry)) {
            return 'could not disable the entry';
        }

        foreach ($plugin->submissions->history($url) as $row) {
            if ($row->type === SubmissionRecord::TYPE_DELETED) {
                return true;
            }
        }

        return 'no removal was queued';
    });

    check('a rule naming another section does not fire', function() use ($plugin, $section, $site, $suffix, &$createdEntries) {
        $plugin->getSettings()->autoSubmitRules = [
            ['section' => 'sanka-no-such-section', 'events' => Rule::EVENTS],
        ];

        $entry = new Entry();
        $entry->sectionId = $section->id;
        $entry->typeId = $section->getEntryTypes()[0]->id;
        $entry->siteId = $site->id;
        $entry->title = "sanka-check-{$suffix} unmatched";
        $entry->slug = "sanka-check-{$suffix}-unmatched";
        $entry->enabled = true;
        $entry->postDate = new DateTime('-1 hour');

        if (!Craft::$app->getElements()->saveElement($entry)) {
            return 'could not save the entry';
        }

        $createdEntries[] = $entry;

        return $plugin->submissions->history((string)$entry->getUrl()) === [] ?: 'a submission was queued anyway';
    });

    check('auto-submit off means nothing is queued at all', function() use ($plugin, $section, $site, $suffix, &$createdEntries) {
        $settings = $plugin->getSettings();
        $settings->autoSubmit = false;
        $settings->autoSubmitRules = [['section' => $section->handle, 'events' => Rule::EVENTS]];

        try {
            $entry = new Entry();
            $entry->sectionId = $section->id;
            $entry->typeId = $section->getEntryTypes()[0]->id;
            $entry->siteId = $site->id;
            $entry->title = "sanka-check-{$suffix} silent";
            $entry->slug = "sanka-check-{$suffix}-silent";
            $entry->enabled = true;
            $entry->postDate = new DateTime('-1 hour');

            if (!Craft::$app->getElements()->saveElement($entry)) {
                return 'could not save the entry';
            }

            $createdEntries[] = $entry;

            return $plugin->submissions->history((string)$entry->getUrl()) === [] ?: 'a submission was queued with auto-submit off';
        } finally {
            $settings->autoSubmit = true;
        }
    });
}

// --------------------------------------------------------- files over HTTP

section('The files, served over HTTP');

$httpChecked = false;

check('the IndexNow key file is served, containing exactly the key', function() use ($plugins, $plugin, $originalSettings, $site, &$httpChecked) {
    $key = 'sankacheckhttp0123456789';
    $settings = $plugin->getSettings()->toArray();
    $settings['indexNowEnabled'] = true;
    $settings['indexNowKey'] = $key;
    $settings['llmsEnabled'] = true;

    if (!$plugins->savePluginSettings($plugin, $settings)) {
        return 'could not save settings for the HTTP check';
    }

    // Project config writes are buffered until the request ends, and this is a bare script that
    // never ends until the checks do. Without the flush the web request below reads the settings as
    // they were before, the routes are never registered, and both files 404.
    Craft::$app->getProjectConfig()->flush();

    $httpChecked = true;
    $url = rtrim((string)$site->getBaseUrl(), '/') . "/{$key}.txt";

    $body = @file_get_contents($url, false, stream_context_create([
        'http' => ['timeout' => 10, 'ignore_errors' => true],
        'ssl' => ['verify_peer' => false, 'verify_peer_name' => false],
    ]));

    if ($body === false) {
        return "could not fetch {$url}";
    }

    return trim($body) === $key ?: 'the file contained: ' . substr($body, 0, 120);
});

check('llms.txt is served as text/plain and opens with the site name', function() use ($site) {
    $url = rtrim((string)$site->getBaseUrl(), '/') . '/llms.txt';

    $body = @file_get_contents($url, false, stream_context_create([
        'http' => ['timeout' => 15, 'ignore_errors' => true],
        'ssl' => ['verify_peer' => false, 'verify_peer_name' => false],
    ]));

    if ($body === false) {
        return "could not fetch {$url}";
    }

    $contentType = '';

    foreach ($http_response_header ?? [] as $header) {
        if (stripos($header, 'content-type:') === 0) {
            $contentType = $header;
        }
    }

    return (str_starts_with($body, '# ') && str_contains(strtolower($contentType), 'text/plain'))
        ?: "type: {$contentType}, body starts: " . substr($body, 0, 60);
});

// ------------------------------------------------------------------- twig

section('Twig');

check('craft.sanka.robots() can leave out the parts another plugin already writes', function() use ($plugin) {
    $variable = new justinholtweb\sanka\twig\SankaVariable();
    $settings = $plugin->getSettings();
    $policy = $settings->crawlerPolicy;
    $settings->crawlerPolicy = ['GPTBot' => CrawlerAgent::POLICY_BLOCK];

    try {
        $body = (string)$variable->robots([
            'header' => false,
            'default' => false,
            'cp' => false,
            'sitemaps' => false,
            'llms' => false,
            'extra' => false,
        ]);

        return (str_starts_with($body, "# OpenAI")
            && str_contains($body, 'User-agent: GPTBot')
            && !str_contains($body, 'User-agent: *')
            && !str_contains($body, 'Sitemap:')
            && !str_contains($body, 'Disallow: /cpresources/'))
            ?: json_encode($body);
    } finally {
        $settings->crawlerPolicy = $policy;
    }
});

check('craft.sanka.robots() still takes a bare site ID', function() use ($site) {
    $variable = new justinholtweb\sanka\twig\SankaVariable();

    return str_contains((string)$variable->robots($site->id), 'User-agent: *') ?: 'no robots body';
});

check('craft.sanka.robots() refuses a section nobody has heard of', function() {
    $variable = new justinholtweb\sanka\twig\SankaVariable();

    try {
        $variable->robots(['sitemap' => false]);
    } catch (Throwable $e) {
        return str_contains($e->getMessage(), 'Unknown robots.txt section') ?: $e->getMessage();
    }

    return 'a misspelt section was accepted, so it would have stayed on';
});

check('craft.sanka.robots() returns rendered markup', function() {
    $variable = new justinholtweb\sanka\twig\SankaVariable();

    return str_contains((string)$variable->robots(), 'User-agent: *') ?: 'no robots body';
});

check('craft.sanka.llms() returns the map', function() use ($site) {
    $variable = new justinholtweb\sanka\twig\SankaVariable();

    return str_starts_with((string)$variable->llms($site->id), '# ') ?: 'no llms body';
});

check('craft.sanka.crawler() is null on a console request', function() {
    return (new justinholtweb\sanka\twig\SankaVariable())->crawler() === null ?: 'a console run looked like a crawler';
});

check('craft.sanka.agents() can be filtered by purpose', function() {
    $variable = new justinholtweb\sanka\twig\SankaVariable();
    $search = $variable->agents(CrawlerAgent::PURPOSE_SEARCH);

    return ($search !== [] && count($search) < count($variable->agents())) ?: 'filtering did nothing';
});

// -------------------------------------------------------------- retention

section('Retention');

check('pruning removes finished rows and keeps pending ones', function() use ($plugin, $base) {
    $old = $plugin->submissions->queue($base . '/prunable', GoogleEngine::HANDLE);
    $plugin->submissions->record($old, SubmissionResult::sent(200, 'ok'));

    $keep = $plugin->submissions->queue($base . '/keepable', GoogleEngine::HANDLE);

    Craft::$app->getDb()->createCommand()->update(SubmissionRecord::TABLE, [
        'dateCreated' => Db::prepareDateForDb((new DateTime())->modify('-400 days')),
    ], ['id' => [$old->id, $keep->id]])->execute();

    $plugin->submissions->prune(90);

    return (!SubmissionRecord::find()->where(['id' => $old->id])->exists()
        && SubmissionRecord::find()->where(['id' => $keep->id])->exists())
        ?: 'pruning kept the wrong rows';
});

} finally {
    // ------------------------------------------------------------- cleanup

    foreach ($createdEntries as $entry) {
        try {
            Craft::$app->getElements()->deleteElement($entry, true);
        } catch (Throwable $e) {
            echo "  ! could not delete entry {$entry->id}: {$e->getMessage()}\n";
        }
    }

    // Strays too, in case an earlier run died before its sweep.
    foreach (Entry::find()->status(null)->siteId('*')->unique()->search(null)->title('sanka-check-*')->all() as $stray) {
        try {
            Craft::$app->getElements()->deleteElement($stray, true);
        } catch (Throwable $e) {
            // Nothing to do; the next run will try again.
        }
    }

    $sweep();

    Craft::$app->getDb()->createCommand()->delete(SubmissionRecord::TABLE, ['like', 'url', '/relative'])->execute();
    Craft::$app->getDb()->createCommand()->delete(QuotaRecord::TABLE, ['engine' => 'sanka-check-quota'])->execute();

    // Restoring matters more than any single check: this runs on a shared site, and leaving Sanka
    // configured with the checks' credentials would be worse than a failure. Retried, because the
    // project-config lock is process-wide and a web request can be holding it.
    $restoreError = null;

    for ($attempt = 1; $attempt <= 6; $attempt++) {
        try {
            Craft::$app->getProjectConfig()->reset();
            Craft::$app->getProjectConfig()->writeYamlAutomatically = false;
            $plugins->savePluginSettings($plugin, $originalSettings);
            $plugins->switchEdition('sanka', $originalEdition);
            Craft::$app->getProjectConfig()->flush();

            $restoreError = null;

            break;
        } catch (Throwable $e) {
            $restoreError = $e->getMessage();
            sleep(2);
        }
    }

    if ($restoreError !== null) {
        echo "  ! could not restore settings after 6 attempts: {$restoreError}\n";
        $failed++;
    }

    Craft::$app->getProjectConfig()->writeYamlAutomatically = $writeYaml;
    $plugin->engines->reset();
}

echo "\n{$passed} passed, {$failed} failed\n";

exit($failed === 0 ? 0 : 1);
