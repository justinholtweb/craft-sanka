<?php

declare(strict_types=1);

namespace justinholtweb\sanka\console\controllers;

use craft\console\Controller;
use craft\elements\Entry;
use craft\helpers\Console;
use justinholtweb\sanka\models\Edition;
use justinholtweb\sanka\Plugin;
use justinholtweb\sanka\records\SubmissionRecord;
use justinholtweb\sanka\services\Submissions;
use yii\console\ExitCode;

/**
 * `php craft sanka/submit/…`
 */
class SubmitController extends Controller
{
    public $defaultAction = 'url';

    /** Comma-separated engine handles. Defaults to every usable engine. */
    public ?string $engines = null;

    /** `update` or `delete`. */
    public string $type = SubmissionRecord::TYPE_UPDATED;

    /** Queue without draining, leaving it to the queue runner. */
    public bool $queueOnly = false;

    public function options($actionID): array
    {
        return array_merge(parent::options($actionID), ['engines', 'type', 'queueOnly']);
    }

    /**
     * Submit one URL.
     */
    public function actionUrl(string $url): int
    {
        $plugin = Plugin::getInstance();
        $rows = $plugin->submissions->queueUrl($url, $this->engineList(), $this->type, Submissions::REASON_COMMAND);

        if ($rows === []) {
            $this->stdout("Nothing queued — either every engine already has this URL waiting, or none are usable.\n", Console::FG_YELLOW);

            return ExitCode::OK;
        }

        return $this->finish(count($rows));
    }

    /**
     * Submit every live entry in a section.
     */
    public function actionSection(string $handle): int
    {
        $plugin = Plugin::getInstance();

        if (!Edition::allowsBulk($plugin->isPro())) {
            $this->stderr("Bulk submission is a Pro feature.\n", Console::FG_RED);

            return ExitCode::UNAVAILABLE;
        }

        $queued = 0;
        $entries = 0;

        foreach (Entry::find()->section($handle)->status(Entry::STATUS_LIVE)->siteId('*')->unique()->each(100) as $entry) {
            $entries++;
            $queued += count($plugin->submissions->queueElement($entry, $this->type, Submissions::REASON_BULK, $this->engineList()));
        }

        $this->stdout("{$entries} entries considered.\n");

        return $this->finish($queued);
    }

    /**
     * Resubmit the sitemaps.
     */
    public function actionSitemaps(): int
    {
        $plugin = Plugin::getInstance();
        $engine = $plugin->engines->getSitemap();

        if (!Edition::allowsSitemaps($plugin->isPro())) {
            $this->stderr("Sitemap resubmission is a Pro feature.\n", Console::FG_RED);

            return ExitCode::UNAVAILABLE;
        }

        $queued = 0;

        foreach ($engine->sitemapUrls() as $url) {
            $this->stdout("  {$url}\n", Console::FG_GREY);

            if ($plugin->submissions->queue($url, $engine->handle(), SubmissionRecord::TYPE_UPDATED, Submissions::REASON_COMMAND) !== null) {
                $queued++;
            }
        }

        return $this->finish($queued);
    }

    /**
     * Ask Google what it last heard about a URL.
     */
    public function actionStatus(string $url): int
    {
        $plugin = Plugin::getInstance();
        $google = $plugin->engines->getGoogle();

        if (!$google->isConfigured()) {
            foreach ($google->problems() as $problem) {
                $this->stderr("{$problem}\n", Console::FG_RED);
            }

            return ExitCode::CONFIG;
        }

        $metadata = $google->metadata($url);

        if ($metadata === null) {
            $this->stdout("Google has no record of that URL.\n", Console::FG_YELLOW);

            return ExitCode::OK;
        }

        $this->stdout(json_encode($metadata, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");

        return ExitCode::OK;
    }

    // ----------------------------------------------------------------- private

    /**
     * @return list<string>|null
     */
    private function engineList(): ?array
    {
        if ($this->engines === null || trim($this->engines) === '') {
            return null;
        }

        return array_values(array_filter(array_map('trim', explode(',', $this->engines))));
    }

    private function finish(int $queued): int
    {
        $this->stdout("{$queued} submissions queued.\n", Console::FG_GREEN);

        if ($this->queueOnly || $queued === 0) {
            return ExitCode::OK;
        }

        $counts = Plugin::getInstance()->dispatcher->drain();

        $this->stdout(sprintf(
            "%d sent · %d skipped · %d retrying · %d failed\n",
            $counts['sent'],
            $counts['skipped'],
            $counts['retrying'],
            $counts['failed'],
        ), $counts['failed'] > 0 ? Console::FG_YELLOW : Console::FG_GREEN);

        return ExitCode::OK;
    }
}
