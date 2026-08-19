<?php

declare(strict_types=1);

namespace justinholtweb\sanka\console\controllers;

use craft\console\Controller;
use craft\helpers\Console;
use justinholtweb\sanka\models\Edition;
use justinholtweb\sanka\Plugin;
use yii\console\ExitCode;

/**
 * `php craft sanka/crawlers/…`
 */
class CrawlersController extends Controller
{
    public $defaultAction = 'summary';

    /** Rows to check per run. */
    public int $limit = 200;

    /** Days to look back. */
    public int $days = 30;

    public function options($actionID): array
    {
        return match ($actionID) {
            'verify' => array_merge(parent::options($actionID), ['limit']),
            'summary' => array_merge(parent::options($actionID), ['days']),
            default => parent::options($actionID),
        };
    }

    /**
     * Which AI agents have been reading the site.
     */
    public function actionSummary(): int
    {
        if (!$this->requirePro()) {
            return ExitCode::UNAVAILABLE;
        }

        $rows = Plugin::getInstance()->crawlers->summary($this->days);

        if ($rows === []) {
            $this->stdout("No AI crawler activity recorded. Is the visit log switched on?\n", Console::FG_YELLOW);

            return ExitCode::OK;
        }

        foreach ($rows as $row) {
            $this->stdout(str_pad($row['label'], 28), Console::FG_CYAN);
            $this->stdout(str_pad($row['purpose'], 11), Console::FG_GREY);
            $this->stdout(sprintf('%6d hits', $row['hits']));

            if ($row['verified'] > 0 || $row['spoofed'] > 0) {
                $this->stdout(sprintf('   %d verified', $row['verified']), Console::FG_GREEN);

                if ($row['spoofed'] > 0) {
                    $this->stdout(sprintf('   %d forged', $row['spoofed']), Console::FG_RED);
                }
            }

            $this->stdout('   last ' . ($row['lastSeen'] ?? '—') . "\n", Console::FG_GREY);
        }

        return ExitCode::OK;
    }

    /**
     * Forward-confirmed reverse DNS over unchecked rows.
     *
     * Worth running on a schedule: a user agent is one line of curl to forge, and a log that treats
     * a claim as a fact is worse than no log.
     */
    public function actionVerify(): int
    {
        if (!$this->requirePro()) {
            return ExitCode::UNAVAILABLE;
        }

        $counts = Plugin::getInstance()->crawlers->verifyPending($this->limit);

        $this->stdout(sprintf(
            "%d checked · %d genuine · %d forged · %d unverifiable\n",
            $counts['checked'],
            $counts['verified'],
            $counts['spoofed'],
            $counts['unknown'],
        ), $counts['spoofed'] > 0 ? Console::FG_YELLOW : Console::FG_GREEN);

        return ExitCode::OK;
    }

    /**
     * Print the robots.txt Sanka would serve.
     */
    public function actionRobots(): int
    {
        if (!$this->requirePro()) {
            return ExitCode::UNAVAILABLE;
        }

        $plugin = Plugin::getInstance();
        $static = $plugin->crawlers->staticRobotsPath();

        if ($static !== null) {
            $this->stderr("Note: {$static} exists and would win over anything Craft routes.\n\n", Console::FG_YELLOW);
        }

        $this->stdout($plugin->crawlers->robotsTxt());

        return ExitCode::OK;
    }

    private function requirePro(): bool
    {
        if (Edition::allowsGeo(Plugin::getInstance()->isPro())) {
            return true;
        }

        $this->stderr("The GEO features are a Pro feature.\n", Console::FG_RED);

        return false;
    }
}
