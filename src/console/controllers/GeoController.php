<?php

declare(strict_types=1);

namespace justinholtweb\sanka\console\controllers;

use Craft;
use craft\console\Controller;
use craft\elements\Entry;
use craft\helpers\Console;
use justinholtweb\sanka\models\Edition;
use justinholtweb\sanka\models\GeoFinding;
use justinholtweb\sanka\models\GeoReport;
use justinholtweb\sanka\Plugin;
use yii\console\ExitCode;

/**
 * `php craft sanka/geo/…`
 */
class GeoController extends Controller
{
    public $defaultAction = 'audit';

    /** Print the long file instead of the map. */
    public bool $full = false;

    /** Only show findings that are not passing. */
    public bool $problemsOnly = false;

    public function options($actionID): array
    {
        return match ($actionID) {
            'llms' => array_merge(parent::options($actionID), ['full']),
            'audit', 'section' => array_merge(parent::options($actionID), ['problemsOnly']),
            default => parent::options($actionID),
        };
    }

    /**
     * Print llms.txt.
     */
    public function actionLlms(?string $site = null): int
    {
        if (!$this->requirePro()) {
            return ExitCode::UNAVAILABLE;
        }

        $siteModel = $site !== null
            ? Craft::$app->getSites()->getSiteByHandle($site)
            : Craft::$app->getSites()->getPrimarySite();

        if ($siteModel === null) {
            $this->stderr("No such site: {$site}\n", Console::FG_RED);

            return ExitCode::DATAERR;
        }

        $llms = Plugin::getInstance()->llms;
        $this->stdout($this->full ? $llms->full($siteModel->id) : $llms->map($siteModel->id));

        return ExitCode::OK;
    }

    /**
     * Audit one URL.
     */
    public function actionAudit(string $url): int
    {
        if (!$this->requirePro()) {
            return ExitCode::UNAVAILABLE;
        }

        $report = Plugin::getInstance()->geo->auditUrl($url);
        $this->printReport($report);

        return $report->ofStatus(GeoFinding::FAIL) === [] ? ExitCode::OK : ExitCode::SOFTWARE;
    }

    /**
     * Audit every live entry in a section and print the ones that need work.
     */
    public function actionSection(string $handle, int $limit = 25): int
    {
        if (!$this->requirePro()) {
            return ExitCode::UNAVAILABLE;
        }

        $plugin = Plugin::getInstance();
        $worst = [];

        foreach (Entry::find()->section($handle)->status(Entry::STATUS_LIVE)->limit($limit)->each(10) as $entry) {
            try {
                $report = $plugin->geo->auditElement($entry);
            } catch (\Throwable $e) {
                $this->stdout(str_pad((string)$entry->title, 44) . " skipped ({$e->getMessage()})\n", Console::FG_GREY);

                continue;
            }

            $worst[] = [$report->score(), (string)$entry->title, $report];
        }

        usort($worst, static fn(array $a, array $b): int => $a[0] <=> $b[0]);

        foreach ($worst as [$score, $title, $report]) {
            $colour = $score >= 80 ? Console::FG_GREEN : ($score >= 55 ? Console::FG_YELLOW : Console::FG_RED);
            $this->stdout(str_pad((string)$score, 5), $colour);
            $this->stdout(str_pad(mb_substr($title, 0, 50), 52));
            $this->stdout((string)$report->url . "\n", Console::FG_GREY);
        }

        return ExitCode::OK;
    }

    // ----------------------------------------------------------------- private

    /** Not `render()`: `yii\base\Controller` already has a public method of that name. */
    private function printReport(GeoReport $report): void
    {
        $score = $report->score();
        $colour = $score >= 80 ? Console::FG_GREEN : ($score >= 55 ? Console::FG_YELLOW : Console::FG_RED);

        $this->stdout("\n{$report->url}\n", Console::FG_CYAN);
        $this->stdout("Overall {$score}/100\n", $colour);

        foreach ($report->scores() as $dimension => $dimensionScore) {
            $this->stdout('  ' . str_pad($dimension, 14) . "{$dimensionScore}\n", Console::FG_GREY);
        }

        $this->stdout("\n");

        foreach ($report->findings as $finding) {
            if ($this->problemsOnly && $finding->status === GeoFinding::PASS) {
                continue;
            }

            [$mark, $markColour] = match ($finding->status) {
                GeoFinding::PASS => ['pass', Console::FG_GREEN],
                GeoFinding::WARN => ['warn', Console::FG_YELLOW],
                default => ['fail', Console::FG_RED],
            };

            $this->stdout(str_pad($mark, 6), $markColour);
            $this->stdout(str_pad($finding->label, 26));
            $this->stdout($finding->message . "\n");

            if ($finding->remediation !== '') {
                $this->stdout('        ' . $finding->remediation . "\n", Console::FG_GREY);
            }
        }

        $this->stdout("\n");
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
