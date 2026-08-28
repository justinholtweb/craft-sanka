<?php

declare(strict_types=1);

namespace justinholtweb\sanka\models;

use Craft;
use craft\base\Model;
use craft\helpers\StringHelper;
use justinholtweb\sanka\engines\IndexNowEngine;
use ReflectionNamedType;
use ReflectionProperty;

/**
 * Everything Sanka is configured with.
 *
 * Nothing here is `required`. A `required` rule on a credential makes a fresh install unable to
 * save *any* setting until that credential is filled in, because `savePluginSettings()` fails
 * validation wholesale — so correctness is validated when a value is present instead.
 *
 * The auto-submit rules and the crawler policy live here rather than in their own project-config
 * paths. They are pure configuration with no derived data hanging off them, so there is nothing to
 * garbage-collect and no config handlers to keep idempotent.
 */
class Settings extends Model
{
    /** Disallow the control panel only when its trigger is Craft's default. */
    public const CP_AUTO = 'auto';

    public const CP_ALWAYS = 'always';

    public const CP_NEVER = 'never';

    public const CP_MODES = [self::CP_AUTO, self::CP_ALWAYS, self::CP_NEVER];

    // ---------------------------------------------------------------- engines

    public bool $googleEnabled = false;

    /**
     * Raw service account JSON, or a path to the file Google Cloud downloaded.
     *
     * Never rendered back into a template and never logged — {@see Settings::credentialSummary()}
     * is what the settings screen shows.
     */
    public string $googleCredentials = '';

    /** Google's documented default is 200 publishes per project per day. */
    public int $googleDailyQuota = 200;

    public bool $indexNowEnabled = false;

    /** 8–128 characters of `[A-Za-z0-9-]`. Generated on install; regenerable from the settings screen. */
    public string $indexNowKey = '';

    /**
     * Which participating endpoint receives the submission. They share it between themselves, so
     * this is a preference rather than a fan-out.
     */
    public string $indexNowEndpoint = IndexNowEngine::DEFAULT_ENDPOINT;

    /** A courtesy cap. IndexNow publishes no daily quota, only a rate limit. */
    public int $indexNowDailyQuota = 10000;

    /** Pro. */
    public bool $sitemapEnabled = false;

    /**
     * Absolute sitemap URLs, as a flat list of strings.
     *
     * Empty means “ask the installed SEO plugin”, then fall back to each site's `/sitemap.xml`.
     *
     * The control panel posts this as editable-table rows — `[rowId => ['url' => '…']]` — and
     * {@see self::setAttributes()} flattens it, for the same reason the rules are normalised there.
     */
    public array $sitemapUrls = [];

    // ------------------------------------------------------------- submitting

    /**
     * Nothing leaves the server while this is on, and the ledger records what *would* have been
     * sent. On by default, because the first thing anyone should do with a tool that can spend a
     * 200-a-day quota is watch it for a week.
     */
    public bool $dryRun = true;

    public bool $autoSubmit = true;

    /**
     * Allow URLs on hosts no engine could reach — `.ddev.site`, `.test`, `localhost`, a private IP.
     *
     * Off, because on a development machine every submission would fail and the reason would look
     * like a bug in Sanka rather than the address being unreachable. Turned on only to exercise the
     * submission path locally, which is exactly what the integration checks do.
     */
    public bool $allowLocalHosts = false;

    /** @var array<int, array<string, mixed>> raw {@see Rule} configs */
    public array $autoSubmitRules = [];

    /**
     * Seconds before the same URL may be sent to the same engine again.
     *
     * This is what stops twenty saves in a minute costing twenty of the two hundred daily
     * publishes an operator gets.
     */
    public int $cooldown = 300;

    public int $maxAttempts = 4;

    /** Seconds added to the wait after each failure, doubling. */
    public int $retryBackoff = 60;

    /** How many pending rows one drain may take. */
    public int $drainLimit = 200;

    /** Days a submission row is kept before garbage collection removes it. */
    public int $retentionDays = 90;

    /** Pro. Queue a sweep of everything that changed since the last run. */
    public bool $sweepEnabled = false;

    public int $sweepWindow = 86400;

    // -------------------------------------------------------------------- GEO

    /** Pro. Serve `/llms.txt`. */
    public bool $llmsEnabled = false;

    /** Pro. Serve `/llms-full.txt` as well — the same map with body text inlined. */
    public bool $llmsFullEnabled = false;

    /** Section handles included in llms.txt, in order. Empty means every section with URLs. */
    public array $llmsSections = [];

    /** The blockquote under the H1. Empty falls back to the site name. */
    public string $llmsSummary = '';

    public int $llmsCacheDuration = 3600;

    /** Pro. Serve `/robots.txt` from Sanka, including the crawler policy. */
    public bool $robotsEnabled = false;

    /** Extra lines appended to the generated robots.txt verbatim. */
    public string $robotsExtra = '';

    /**
     * Whether the generated robots.txt disallows the control panel.
     *
     * `auto` emits the line only when `cpTrigger` is Craft's default. A default trigger is public
     * knowledge and the line is conventional; a *customised* one was customised to keep the control
     * panel out of sight, and robots.txt is the most-read file on the site — publishing the path
     * there hands it to everybody who asks. Disallowing it buys nothing either way: the control
     * panel requires a login, and a crawler that ignores robots.txt is exactly the one you were
     * worried about.
     */
    public string $robotsDisallowCp = self::CP_AUTO;

    /** @var array<string, string> agent token => `allow` | `block` */
    public array $crawlerPolicy = [];

    /** Pro. Record every request from a known AI agent. */
    public bool $crawlerLogEnabled = false;

    public int $crawlerRetentionDays = 30;

    /** Seconds allowed for the same-origin fetch the readiness audit makes. */
    public int $auditTimeout = 15;

    // --------------------------------------------------------------- lifecycle

    protected function defineRules(): array
    {
        return [
            [[
                'googleEnabled', 'indexNowEnabled', 'sitemapEnabled', 'dryRun', 'autoSubmit',
                'sweepEnabled', 'llmsEnabled', 'llmsFullEnabled', 'robotsEnabled', 'crawlerLogEnabled',
                'allowLocalHosts',
            ], 'boolean'],
            [['googleDailyQuota'], 'integer', 'min' => 1, 'max' => 100000],
            [['indexNowDailyQuota'], 'integer', 'min' => 1, 'max' => 1000000],
            [['cooldown'], 'integer', 'min' => 0, 'max' => 604800],
            [['maxAttempts'], 'integer', 'min' => 1, 'max' => 20],
            [['retryBackoff'], 'integer', 'min' => 1, 'max' => 86400],
            [['drainLimit'], 'integer', 'min' => 1, 'max' => 10000],
            [['retentionDays', 'crawlerRetentionDays'], 'integer', 'min' => 1, 'max' => 3650],
            [['sweepWindow'], 'integer', 'min' => 60, 'max' => 2592000],
            [['llmsCacheDuration'], 'integer', 'min' => 0, 'max' => 604800],
            [['auditTimeout'], 'integer', 'min' => 1, 'max' => 120],
            [['indexNowEndpoint'], 'in', 'range' => array_keys(IndexNowEngine::ENDPOINTS)],
            [['robotsDisallowCp'], 'in', 'range' => self::CP_MODES],
            [['indexNowKey'], 'match', 'pattern' => '/^[A-Za-z0-9\-]{8,128}$/', 'skipOnEmpty' => true,
                'message' => 'The IndexNow key must be 8–128 characters of letters, numbers and dashes.'],
            [['googleCredentials'], 'validateGoogleCredentials', 'skipOnEmpty' => true],
            [['sitemapUrls'], 'validateSitemapUrls', 'skipOnEmpty' => false],
            [['autoSubmitRules'], 'validateAutoSubmitRules', 'skipOnEmpty' => false],
            [['crawlerPolicy'], 'validateCrawlerPolicy', 'skipOnEmpty' => false],
        ];
    }

    /**
     * Craft posts every scalar as a string, and clearing a number field posts `''` — which is a
     * `TypeError` against a typed `int` property, not a zero. So each incoming value is coerced
     * against the property's declared type, and anything unreadable leaves the default in place.
     */
    public function setAttributes($values, $safeOnly = true): void
    {
        if (!is_array($values)) {
            parent::setAttributes($values, $safeOnly);

            return;
        }

        foreach ($values as $name => $value) {
            if (!property_exists($this, $name)) {
                continue;
            }

            $values[$name] = $this->coerce($name, $value);
        }

        parent::setAttributes($values, $safeOnly);

        // Craft's editable table posts `name[rowId][column]`, so a table of sitemap URLs arrives as
        // a list of one-key arrays rather than the list of strings this holds. Flattening here is
        // what stops a saved row reaching the template as an array — which renders as “Array to
        // string conversion” and takes the whole settings screen down — and what stops
        // {@see \justinholtweb\sanka\engines\SitemapEngine::sitemapUrls()} quietly skipping every
        // configured sitemap because none of them is a string.
        if (array_key_exists('sitemapUrls', $values)) {
            $this->sitemapUrls = self::flattenUrls($this->sitemapUrls);
        }

        // Rules arrive from the control panel in a flat, one-column-per-checkbox shape. Normalising
        // here means everything downstream — validation, project config, the services — only ever
        // sees the canonical one.
        if (array_key_exists('autoSubmitRules', $values)) {
            $this->autoSubmitRules = array_values(array_map(
                static fn(mixed $row): array => is_array($row) ? Rule::fromConfig($row)->toConfig() : [],
                array_filter($this->autoSubmitRules, 'is_array'),
            ));
        }
    }

    // -------------------------------------------------------------- accessors

    /**
     * @return list<Rule>
     */
    public function getAutoRules(): array
    {
        $rules = [];

        foreach ($this->autoSubmitRules as $config) {
            if (is_array($config)) {
                $rules[] = Rule::fromConfig($config);
            }
        }

        return $rules;
    }

    /**
     * The policy for one agent, falling back to the registry default.
     */
    public function policyFor(string $token): string
    {
        $policy = $this->crawlerPolicy[$token] ?? null;

        return $policy === CrawlerAgent::POLICY_BLOCK ? CrawlerAgent::POLICY_BLOCK : CrawlerAgent::defaultPolicy();
    }

    /**
     * @return list<string> tokens currently blocked
     */
    public function blockedAgents(): array
    {
        $blocked = [];

        foreach (CrawlerAgent::all() as $agent) {
            if ($this->policyFor($agent->token) === CrawlerAgent::POLICY_BLOCK) {
                $blocked[] = $agent->token;
            }
        }

        return $blocked;
    }

    /**
     * A non-secret description of the configured service account, safe for the settings screen and
     * for a log line. Never returns any part of the private key.
     */
    public function credentialSummary(): ?string
    {
        if (trim($this->googleCredentials) === '') {
            return null;
        }

        if (!str_starts_with(trim($this->googleCredentials), '{')) {
            return Craft::t('sanka', 'Key file: {path}', ['path' => $this->googleCredentials]);
        }

        $decoded = json_decode($this->googleCredentials, true);
        $email = is_array($decoded) ? (string)($decoded['client_email'] ?? '') : '';

        return $email !== ''
            ? $email
            : Craft::t('sanka', 'Inline JSON ({bytes} bytes)', ['bytes' => strlen($this->googleCredentials)]);
    }

    /**
     * A fresh IndexNow key. Hex is used rather than the full permitted alphabet because several
     * endpoints have historically been fussy about case.
     */
    public static function generateIndexNowKey(): string
    {
        return strtolower(StringHelper::UUID() . StringHelper::UUID());
    }

    // ------------------------------------------------------------- validators

    public function validateGoogleCredentials(string $attribute): void
    {
        $value = trim($this->googleCredentials);

        if ($value === '') {
            return;
        }

        if (!str_starts_with($value, '{')) {
            if (!is_file($value) || !is_readable($value)) {
                $this->addError($attribute, Craft::t('sanka', 'That key file cannot be read. Paste the JSON itself if the file is not readable by the web server.'));
            }

            return;
        }

        $decoded = json_decode($value, true);

        if (!is_array($decoded)) {
            $this->addError($attribute, Craft::t('sanka', 'That is not valid JSON. Paste the service account key exactly as Google Cloud downloaded it.'));

            return;
        }

        foreach (['client_email', 'private_key'] as $field) {
            if (trim((string)($decoded[$field] ?? '')) === '') {
                $this->addError($attribute, Craft::t('sanka', 'The service account JSON is missing “{field}”.', ['field' => $field]));
            }
        }
    }

    public function validateSitemapUrls(string $attribute): void
    {
        foreach ($this->sitemapUrls as $url) {
            // Anything that is not a string got past normalisation, which means it came from a
            // hand-edited project config rather than the control panel. Saying so beats skipping it
            // and letting the engine silently ignore the sitemap the operator asked for.
            if (!is_string($url)) {
                $this->addError($attribute, Craft::t('sanka', 'Sitemap URLs must be a plain list of absolute URLs.'));

                continue;
            }

            if ($url === '') {
                continue;
            }

            if (!preg_match('#^https?://#i', $url)) {
                $this->addError($attribute, Craft::t('sanka', 'Sitemap URLs must be absolute: {url}', ['url' => $url]));
            }
        }
    }

    public function validateAutoSubmitRules(string $attribute): void
    {
        foreach ($this->autoSubmitRules as $i => $config) {
            if (!is_array($config)) {
                $this->addError($attribute, Craft::t('sanka', 'Rule {n} is malformed.', ['n' => $i + 1]));

                continue;
            }

            $rule = Rule::fromConfig($config);

            if (!$rule->validate()) {
                foreach ($rule->getErrorSummary(false) as $error) {
                    $this->addError($attribute, Craft::t('sanka', 'Rule {n}: {error}', ['n' => $i + 1, 'error' => $error]));
                }
            }
        }
    }

    public function validateCrawlerPolicy(string $attribute): void
    {
        foreach ($this->crawlerPolicy as $token => $policy) {
            if (CrawlerAgent::find((string)$token) === null) {
                $this->addError($attribute, Craft::t('sanka', 'Unknown crawler: {token}', ['token' => (string)$token]));
            }

            if (!in_array($policy, [CrawlerAgent::POLICY_ALLOW, CrawlerAgent::POLICY_BLOCK], true)) {
                $this->addError($attribute, Craft::t('sanka', 'Unknown policy for {token}: {policy}', [
                    'token' => (string)$token,
                    'policy' => is_scalar($policy) ? (string)$policy : gettype($policy),
                ]));
            }
        }
    }

    // ----------------------------------------------------------------- private

    /**
     * A list of sitemap URLs, from either shape it arrives in.
     *
     * Accepts the flat list project config holds and the `[rowId => ['url' => '…']]` rows the
     * control panel posts, and takes the single value out of a row whatever the column is called,
     * so a row that reached project config under some other key is still recovered rather than
     * thrown away.
     *
     * @return list<string>
     */
    private static function flattenUrls(array $values): array
    {
        $urls = [];

        foreach ($values as $value) {
            if (is_array($value)) {
                $value = $value['url'] ?? (count($value) === 1 ? reset($value) : null);
            }

            if (!is_string($value)) {
                continue;
            }

            $value = trim($value);

            if ($value !== '') {
                $urls[] = $value;
            }
        }

        return array_values(array_unique($urls));
    }

    private function coerce(string $name, mixed $value): mixed
    {
        try {
            $type = (new ReflectionProperty($this, $name))->getType();
        } catch (\ReflectionException) {
            return $value;
        }

        if (!$type instanceof ReflectionNamedType) {
            return $value;
        }

        // Craft's date and time inputs post arrays; no scalar cast accepts one.
        if (is_array($value) && $type->getName() !== 'array') {
            return $this->{$name};
        }

        return match ($type->getName()) {
            'int' => is_numeric($value) ? (int)$value : $this->{$name},
            'float' => is_numeric($value) ? (float)$value : $this->{$name},
            'bool' => is_scalar($value) ? (bool)$value : $this->{$name},
            'string' => is_scalar($value) ? (string)$value : $this->{$name},
            'array' => is_array($value) ? $value : $this->{$name},
            default => $value,
        };
    }
}
