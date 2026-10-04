<?php
/**
 * Where the Google key lives, and who changes the crawler policy — checked in the plugin-testing
 * harness, partly over HTTP.
 *
 *     docker exec -w /var/www/html ddev-plugin-testing-web php /var/www/craft-sanka/tests/integration/security.php
 *
 * Until 5.0.2 the service account JSON — private key included — was pasted into a setting, which is
 * project config and so committed with the site, and the settings screen rendered it back into a
 * textarea. And the "Manage GEO" permission let a non-admin write the crawler policy to project
 * config, which also threw in production where admin changes are off.
 *
 * The read-only check needs a web request with admin changes off, so it flips
 * CRAFT_ALLOW_ADMIN_CHANGES in the harness .env for a few seconds and always puts it back.
 * Self-cleaning: Sanka's settings are restored exactly as they were.
 */

$root = getcwd();
require $root . '/bootstrap.php';

/** @var craft\console\Application $app */
$app = require CRAFT_VENDOR_PATH . '/craftcms/cms/bootstrap/console.php';

require dirname(__DIR__) . '/support/FakeHttpClient.php';

use craft\db\Query;
use craft\elements\User;
use GuzzleHttp\Client;
use GuzzleHttp\Cookie\CookieJar;
use justinholtweb\sanka\models\CrawlerAgent;
use justinholtweb\sanka\models\Settings;
use justinholtweb\sanka\Plugin;
use justinholtweb\sanka\tests\support\FakeHttpClient;

$passed = 0;
$failed = 0;

function check(string $label, callable $test): void
{
    global $passed, $failed;

    try {
        $result = $test();
    } catch (Throwable $e) {
        $result = get_class($e) . ': ' . $e->getMessage();
    }

    if ($result === true) {
        $passed++;
        echo "  ✓ $label\n";
    } else {
        $failed++;
        echo "  ✗ $label\n    " . (is_string($result) ? $result : var_export($result, true)) . "\n";
    }
}

Craft::$app->getPlugins()->loadPlugins();
$sanka = Plugin::getInstance();
$run = substr(bin2hex(random_bytes(3)), 0, 6);
$password = 'Sanka-' . bin2hex(random_bytes(6));
$envFile = $root . '/.env';
$envBefore = (string)file_get_contents($envFile);
$settingsBefore = Craft::$app->getProjectConfig()->get('plugins.sanka.settings');
$keyFile = Craft::getAlias('@storage') . "/runtime/sanka-security-$run.json";
$cleanup = ['users' => []];

$restoreEnv = static function() use ($envFile, $envBefore) {
    if ((string)file_get_contents($envFile) !== $envBefore) {
        file_put_contents($envFile, $envBefore);
    }
};

$reloadConfig = static function() {
    Craft::$app->getInfo()->configVersion = (string)(new Query())->select('configVersion')->from('{{%info}}')->scalar();
    Craft::$app->getProjectConfig()->reset();
};

/** Write Sanka's stored settings straight to project config, as a deploy (or an old version) would. */
$storeSettings = static function(array $settings, string $why) use ($reloadConfig) {
    $reloadConfig();
    $pc = Craft::$app->getProjectConfig();
    $pc->set('plugins.sanka.settings', $settings, $why);
    $pc->saveModifiedConfigData();
    $pc->writeYamlFiles(true);
};

$stored = static function(string $key) use ($reloadConfig) {
    $reloadConfig();

    $value = Craft::$app->getProjectConfig()->get("plugins.sanka.settings.$key");

    return is_array($value) ? \craft\helpers\ProjectConfig::unpackAssociativeArray($value) : $value;
};

register_shutdown_function(function() use (&$cleanup, $restoreEnv, $storeSettings, $settingsBefore, $keyFile, $stored) {
    $restoreEnv();
    @unlink($keyFile);
    foreach ($cleanup['users'] as $user) {
        Craft::$app->getElements()->deleteElement($user, true);
    }
    if ($stored('googleCredentials') !== ($settingsBefore['googleCredentials'] ?? null) || $stored('crawlerPolicy') !== ($settingsBefore['crawlerPolicy'] ?? null)
        || Craft::$app->getProjectConfig()->get('plugins.sanka.settings') !== $settingsBefore) {
        $storeSettings($settingsBefore, 'Restore after security.php');
    }
});

$withAdminChangesOff = static function(callable $fn) use ($envFile, $envBefore, $restoreEnv) {
    $off = preg_match('/^CRAFT_ALLOW_ADMIN_CHANGES=.*$/m', $envBefore)
        ? preg_replace('/^CRAFT_ALLOW_ADMIN_CHANGES=.*$/m', 'CRAFT_ALLOW_ADMIN_CHANGES=false', $envBefore)
        : rtrim($envBefore) . "\nCRAFT_ALLOW_ADMIN_CHANGES=false\n";
    file_put_contents($envFile, $off);
    try {
        return $fn();
    } finally {
        $restoreEnv();
    }
};

function setEnv(string $name, ?string $value): void
{
    if ($value === null) {
        putenv($name);
        unset($_SERVER[$name], $_ENV[$name]);

        return;
    }
    putenv("$name=$value");
    $_SERVER[$name] = $_ENV[$name] = $value;
}

function client(string $username, string $password): array
{
    $http = new Client(['base_uri' => 'http://localhost/', 'cookies' => new CookieJar(), 'http_errors' => false, 'allow_redirects' => false]);
    $json = ['Accept' => 'application/json'];
    $csrf = static fn() => (string)(json_decode((string)$http->get('index.php?p=actions/users/session-info', ['headers' => $json])->getBody(), true)['csrfTokenValue'] ?? '');
    $login = $http->post('index.php?p=actions/users/login', ['headers' => $json, 'form_params' => ['loginName' => $username, 'password' => $password, 'CRAFT_CSRF_TOKEN' => $csrf()]]);

    if ($login->getStatusCode() !== 200) {
        echo "Could not sign in as $username\n";
        exit(1);
    }

    $post = static fn(string $action, array $params) => $http->post("index.php?p=admin/actions/$action", [
        'headers' => $json,
        'form_params' => $params + ['CRAFT_CSRF_TOKEN' => $csrf()],
    ]);

    return [$http, $post];
}

$account = FakeHttpClient::serviceAccount();
$other = FakeHttpClient::serviceAccount();
$keyBody = trim(explode("\n", json_decode($account['json'], true)['private_key'])[5]);
$envName = 'SANKA_SECURITY_' . strtoupper($run);

$model = static function(string $credentials): Settings {
    $s = new Settings();
    $s->googleCredentials = $credentials;

    return $s;
};

echo "\nWhere the key may live\n";

$storeSettings(['googleCredentials' => ''] + $settingsBefore, 'No key stored');

check('pasting the JSON is refused — it would be committed', function() use ($model, $account) {
    $s = $model($account['json']);

    return !$s->validate(['googleCredentials']) && str_contains(implode(' ', $s->getErrors('googleCredentials')), 'project config') ?: json_encode($s->getErrors());
});

check('an environment variable holding the JSON is fine, and resolves', function() use ($model, $account, $envName) {
    setEnv($envName, $account['json']);
    $s = $model('$' . $envName);
    $summary = (string)$s->credentialSummary();

    return $s->validate(['googleCredentials']) && $s->resolvedGoogleCredentials() === $account['json']
        && str_contains($summary, '$' . $envName) && str_contains($summary, $account['email']) && !str_contains($summary, 'PRIVATE')
        ?: json_encode(['errors' => $s->getErrors(), 'summary' => $summary]);
});

check('…and the engine reads it', function() use ($sanka, $account, $envName) {
    setEnv($envName, $account['json']);
    $settings = $sanka->getSettings();
    $was = $settings->googleCredentials;
    $settings->googleCredentials = '$' . $envName;
    $sanka->engines->reset();
    try {
        $problems = $sanka->engines->getGoogle()->problems();

        return $problems === [] && strlen($sanka->engines->getGoogle()->buildAssertion(time())) > 100 ?: json_encode($problems);
    } finally {
        $settings->googleCredentials = $was;
        $sanka->engines->reset();
    }
});

check('a variable that isn’t set here saves, is reported, and sends nothing', function() use ($model, $sanka, $envName) {
    setEnv($envName, null);
    $s = $model('$' . $envName);
    $settings = $sanka->getSettings();
    $was = $settings->googleCredentials;
    $settings->googleCredentials = '$' . $envName;
    $sanka->engines->reset();
    try {
        $problems = implode(' ', $sanka->engines->getGoogle()->problems());

        return $s->validate(['googleCredentials']) && $s->resolvedGoogleCredentials() === '' && str_contains((string)$s->credentialSummary(), 'isn’t set')
            && str_contains($problems, '$' . $envName)
            ?: json_encode(['errors' => $s->getErrors(), 'summary' => $s->credentialSummary(), 'problems' => $problems]);
    } finally {
        $settings->googleCredentials = $was;
        $sanka->engines->reset();
    }
});

check('a file path given as an alias resolves', function() use ($model, $account, $keyFile, $run) {
    file_put_contents($keyFile, $account['json']);
    $s = $model("@storage/runtime/sanka-security-$run.json");

    return $s->validate(['googleCredentials']) && $s->resolvedGoogleCredentials() === $keyFile ?: json_encode(['errors' => $s->getErrors(), 'resolved' => $s->resolvedGoogleCredentials()]);
});

echo "\nA key stored before 5.0.2\n";

$storeSettings(['googleCredentials' => $account['json']] + $settingsBefore, 'An inline key, as 5.0.1 stored it');

check('keeps validating, so other settings still save', fn() => $model($account['json'])->validate(['googleCredentials']) ?: 'refused');

check('…but a different pasted key is still refused', fn() => !$model($other['json'])->validate(['googleCredentials']) ?: 'accepted');

check('an empty field posted with the kept flag leaves it alone', function() use ($account) {
    $s = new Settings();
    $s->googleCredentials = $account['json'];
    $s->setAttributes(['googleCredentials' => '', 'googleCredentialsKept' => '1', 'googleDailyQuota' => '150'], false);

    return $s->googleCredentials === $account['json'] && $s->googleDailyQuota === 150 ?: 'changed to ' . var_export($s->googleCredentials, true);
});

check('…unless “Remove the stored key” is ticked', function() use ($account) {
    $s = new Settings();
    $s->googleCredentials = $account['json'];
    $s->setAttributes(['googleCredentials' => '', 'googleCredentialsKept' => '1', 'googleCredentialsRemove' => '1'], false);

    return $s->googleCredentials === '' ?: 'kept';
});

check('…and without the flag, an empty field clears, as before', function() use ($account) {
    $s = new Settings();
    $s->googleCredentials = $account['json'];
    $s->setAttributes(['googleCredentials' => ''], false);

    return $s->googleCredentials === '' ?: 'kept';
});

[$adminHttp, $adminPost] = client('admin', 'claudepassword');

check('the settings screen never renders the key, and says to move it', function() use ($adminHttp, $keyBody) {
    $body = (string)$adminHttp->get('index.php?p=admin/settings/plugins/sanka')->getBody();

    return !str_contains($body, 'PRIVATE KEY') && !str_contains($body, $keyBody) && str_contains($body, 'rotate the key')
        && str_contains($body, 'googleCredentialsKept') && str_contains($body, 'sanka-checks@example.iam.gserviceaccount.com')
        ?: json_encode(['key shown' => str_contains($body, 'PRIVATE KEY') || str_contains($body, $keyBody), 'warning' => str_contains($body, 'rotate the key'), 'flag' => str_contains($body, 'googleCredentialsKept')]);
});

$saveSettings = static fn(array $settings) => $adminPost('plugins/save-plugin-settings', ['pluginHandle' => 'sanka', 'settings' => $settings]);

check('saving the screen as rendered keeps the stored key', function() use ($saveSettings, $stored, $account) {
    $status = $saveSettings(['googleCredentials' => '', 'googleCredentialsKept' => '1', 'googleDailyQuota' => '199'])->getStatusCode();

    return $status === 200 && $stored('googleCredentials') === $account['json'] && (int)$stored('googleDailyQuota') === 199
        ?: "status $status, quota " . var_export($stored('googleDailyQuota'), true) . ', key kept: ' . var_export($stored('googleCredentials') === $account['json'], true);
});

check('posting a new key as JSON is refused over HTTP too', function() use ($saveSettings, $stored, $account, $other) {
    $status = $saveSettings(['googleCredentials' => $other['json']])->getStatusCode();

    return $status !== 200 && $stored('googleCredentials') === $account['json'] ?: "status $status";
});

check('moving it to an environment variable takes the key out of project config', function() use ($saveSettings, $stored, $envName) {
    $status = $saveSettings(['googleCredentials' => '$' . $envName, 'googleCredentialsKept' => '1'])->getStatusCode();
    $yaml = (string)@file_get_contents(Craft::getAlias('@config') . '/project/project.yaml');

    return $status === 200 && $stored('googleCredentials') === '$' . $envName && !str_contains($yaml, 'PRIVATE KEY') ?: "status $status, stored " . substr((string)$stored('googleCredentials'), 0, 40);
});

echo "\nThe crawler policy\n";

$storeSettings($settingsBefore, 'Back to the harness settings');

$geo = new User(['username' => "sanka-geo-$run", 'email' => "sanka-geo-$run@example.com", 'newPassword' => $password]);
Craft::$app->getElements()->saveElement($geo, false);
Craft::$app->getUsers()->activateUser($geo);
Craft::$app->getUserPermissions()->saveUserPermissions($geo->id, ['accesscp', 'accessplugin-sanka', 'sanka:view', 'sanka:managegeo']);
$cleanup['users'][] = $geo;

[$geoHttp, $geoPost] = client($geo->username, $password);
$token = CrawlerAgent::all()[0]->token;
$policy = static fn(string $value) => ['policy' => [$token => $value], 'robotsExtra' => "# sanka security $run"];

check('the GEO permission alone can’t change it', function() use ($geoPost, $policy, $stored, $token) {
    $before = $stored('crawlerPolicy');
    $status = $geoPost('sanka/crawlers/policy', $policy('block'))->getStatusCode();

    return $status === 403 && $stored('crawlerPolicy') === $before ?: "status $status, policy " . json_encode($stored('crawlerPolicy'));
});

check('…and sees the screen read-only, with the reason', function() use ($geoHttp) {
    $body = (string)$geoHttp->get('index.php?p=admin/sanka/crawlers')->getBody();

    return str_contains($body, 'An admin changes it') && !str_contains($body, 'Save policy') ?: 'editable';
});

check('…but can still verify the log', function() use ($geoPost) {
    $status = $geoPost('sanka/crawlers/verify', [])->getStatusCode();

    return $status === 200 ?: "status $status";
});

check('with admin changes off, not even an admin can', fn() => $withAdminChangesOff(function() use ($adminPost, $policy, $stored, $run) {
    $status = $adminPost('sanka/crawlers/policy', $policy('block'))->getStatusCode();

    return $status === 403 && !str_contains((string)$stored('robotsExtra'), $run) ?: "status $status";
}));

check('an admin can', function() use ($adminPost, $policy, $stored, $token, $run) {
    $status = $adminPost('sanka/crawlers/policy', $policy('block'))->getStatusCode();

    return $status === 200 && ($stored('crawlerPolicy')[$token] ?? null) === 'block' && str_contains((string)$stored('robotsExtra'), $run)
        ?: "status $status, policy " . json_encode($stored('crawlerPolicy'));
});

echo "\n$passed passed, $failed failed\n";
exit($failed === 0 ? 0 : 1);
