<?php

declare(strict_types=1);

namespace justinholtweb\sanka\console\controllers;

use Craft;
use craft\console\Controller;
use craft\helpers\Console;
use justinholtweb\sanka\models\Edition;
use justinholtweb\sanka\Plugin;
use Throwable;
use yii\console\ExitCode;

/**
 * `php craft sanka/diagnostics`
 *
 * Everything that could be wrong, checked in the order it would break. Written for the support
 * conversation that starts “it isn't submitting anything” — the answer is almost always on this
 * screen, and it is almost never the same answer twice.
 */
class DiagnosticsController extends Controller
{
    public $defaultAction = 'index';

    public function actionIndex(): int
    {
        $plugin = Plugin::getInstance();
        $settings = $plugin->getSettings();
        $problems = 0;

        $this->heading('Edition');
        $this->line('Edition', $plugin->isPro() ? 'Pro' : 'Lite');

        foreach (Edition::problems($settings, $plugin->isPro()) as $problem) {
            $this->warn($problem);
            $problems++;
        }

        $this->heading('Submitting');
        $this->line('Auto-submit', $settings->autoSubmit ? 'on' : 'off');
        $this->line('Dry run', $settings->dryRun ? 'ON — nothing is being sent' : 'off');
        $this->line('Cooldown', "{$settings->cooldown}s");
        $this->line('Rules', (string)count($plugin->rules->all()));

        if ($settings->dryRun) {
            $this->warn('Dry run is on. Submissions are being recorded as skipped and nothing is leaving the server.');
            $problems++;
        }

        if ($plugin->rules->all() === []) {
            $this->warn('No auto-submit rules, so saving an entry submits nothing. Add one on the settings screen.');
            $problems++;
        }

        $this->heading('Engines');

        foreach ($plugin->engines->getAvailable() as $engine) {
            $state = match (true) {
                !$engine->isEnabled() => 'off',
                !$engine->isConfigured() => 'not configured',
                default => 'ready',
            };

            $this->line($engine->label(), $state);

            foreach ($engine->problems() as $problem) {
                if ($engine->isEnabled()) {
                    $this->warn($problem);
                    $problems++;
                } else {
                    $this->hint($problem);
                }
            }

            $remaining = $plugin->quota->remaining($engine);

            if ($remaining !== null) {
                $this->hint("Quota: {$remaining} of {$engine->dailyQuota()} left for {$engine->quotaDate()}.");

                if ($remaining === 0) {
                    $this->warn('Quota exhausted. Nothing more will go to this engine today.');
                    $problems++;
                }
            }

            $this->hint($engine->isEnabled() ? $plugin->submissions->pendingCount($engine->handle()) . ' pending' : '');
        }

        $problems += $this->google();
        $problems += $this->indexNow();
        $problems += $this->geo();

        $this->heading('Result');

        if ($problems === 0) {
            $this->stdout("Nothing to fix.\n", Console::FG_GREEN);

            return ExitCode::OK;
        }

        $this->stdout("{$problems} thing(s) above need attention.\n", Console::FG_YELLOW);

        return ExitCode::SOFTWARE;
    }

    // ----------------------------------------------------------------- private

    private function google(): int
    {
        $plugin = Plugin::getInstance();
        $engine = $plugin->engines->getGoogle();

        if (!$engine->isEnabled()) {
            return 0;
        }

        $this->heading('Google service account');

        if (!$engine->isConfigured()) {
            return 0;
        }

        $summary = $plugin->getSettings()->credentialSummary();
        $this->line('Account', $summary ?? '—');

        if ($plugin->getSettings()->storesInlineGoogleKey()) {
            $this->warn('The private key is stored in Sanka\'s settings, so it is in project config and your repository. Move it to an environment variable or a file outside the web root, and rotate it if the repository has been shared.');
        }

        // Signing is proved locally before anything is blamed on Google. A key that will not sign
        // is a settings problem; a key that signs and is refused is a Search Console problem, and
        // the two are debugged in completely different places.
        try {
            $assertion = $engine->buildAssertion(time());
            $this->line('Signing', 'works (' . strlen($assertion) . ' byte assertion)');
        } catch (Throwable $e) {
            $this->warn("The key could not sign an assertion: {$e->getMessage()}");

            return 1;
        }

        $this->hint('If Google answers 403, the fix is in Search Console: add ' . ($summary ?? 'the service account') . ' as an owner of the property.');
        $this->hint('Publish quota is per Google Cloud *project*, not per site — two Craft installs sharing a key share the 200.');

        return 0;
    }

    private function indexNow(): int
    {
        $plugin = Plugin::getInstance();
        $engine = $plugin->engines->getIndexNow();

        if (!$engine->isEnabled() || !$engine->isConfigured()) {
            return 0;
        }

        $this->heading('IndexNow');
        $this->line('Endpoint', $plugin->getSettings()->indexNowEndpoint);
        $this->line('Key file', $engine->keyUrl());
        $this->hint('The engines fetch that URL to confirm you control the host. It must be publicly reachable — no basic auth, no staging password, no redirect.');

        return 0;
    }

    private function geo(): int
    {
        $plugin = Plugin::getInstance();
        $settings = $plugin->getSettings();

        if (!Edition::allowsGeo($plugin->isPro())) {
            return 0;
        }

        $this->heading('GEO');
        $this->line('llms.txt', $settings->llmsEnabled ? 'served' : 'off');
        $this->line('llms-full.txt', $settings->llmsEnabled && $settings->llmsFullEnabled ? 'served' : 'off');
        $this->line('robots.txt', $settings->robotsEnabled ? 'served by Sanka' : 'not served by Sanka');
        $this->line('Crawler log', $settings->crawlerLogEnabled ? 'on' : 'off');
        $this->line('Blocked agents', (string)count($settings->blockedAgents()));

        $problems = 0;
        $static = $plugin->crawlers->staticRobotsPath();

        if ($settings->robotsEnabled && $static !== null) {
            $this->warn("A static robots.txt exists at {$static} and will be served instead of Sanka's. Delete it, or turn the setting off.");
            $problems++;
        }

        return $problems;
    }

    private function heading(string $text): void
    {
        $this->stdout("\n{$text}\n", Console::FG_CYAN, Console::BOLD);
        $this->stdout(str_repeat('─', mb_strlen($text)) . "\n", Console::FG_GREY);
    }

    private function line(string $label, string $value): void
    {
        $this->stdout('  ' . str_pad($label, 22));
        $this->stdout($value . "\n");
    }

    private function warn(string $text): void
    {
        $this->stdout("  ! {$text}\n", Console::FG_YELLOW);
    }

    /**
     * Named `hint`, not `note`: `craft\console\ControllerTrait` already defines public `note()`,
     * `tip()`, `success()`, `failure()` and `warning()`, and a private method with one of those
     * names is a fatal compile error the moment the class is autoloaded.
     */
    private function hint(string $text): void
    {
        if (trim($text) !== '') {
            $this->stdout("    {$text}\n", Console::FG_GREY);
        }
    }
}
