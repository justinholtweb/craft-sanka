<?php

declare(strict_types=1);

namespace justinholtweb\sanka\console\controllers;

use craft\console\Controller;
use craft\helpers\Console;
use justinholtweb\sanka\jobs\SweepJob;
use justinholtweb\sanka\models\Edition;
use justinholtweb\sanka\Plugin;
use justinholtweb\sanka\records\SubmissionRecord;
use yii\console\ExitCode;

/**
 * `php craft sanka/queue/…`
 *
 * The cron surface. A site that publishes on a schedule, or one whose queue runner is not
 * guaranteed, wants `drain` on a timer; everything else here exists to answer questions without
 * opening the control panel.
 */
class QueueController extends Controller
{
    public $defaultAction = 'drain';

    /** Limit the drain to one engine. */
    public ?string $engine = null;

    /** How many rows to take. Defaults to the configured drain limit. */
    public ?int $limit = null;

    public function options($actionID): array
    {
        return match ($actionID) {
            'drain' => array_merge(parent::options($actionID), ['engine', 'limit']),
            'sweep' => array_merge(parent::options($actionID), ['limit']),
            default => parent::options($actionID),
        };
    }

    /**
     * Send whatever is waiting.
     */
    public function actionDrain(): int
    {
        $counts = Plugin::getInstance()->dispatcher->drain($this->engine, $this->limit);

        if ($counts['considered'] === 0) {
            $this->stdout("Nothing waiting.\n", Console::FG_GREY);

            return ExitCode::OK;
        }

        $this->stdout(sprintf(
            "%d considered · %d sent · %d skipped · %d retrying · %d failed\n",
            $counts['considered'],
            $counts['sent'],
            $counts['skipped'],
            $counts['retrying'],
            $counts['failed'],
        ), $counts['failed'] > 0 ? Console::FG_YELLOW : Console::FG_GREEN);

        if ($counts['quotaBlocked'] > 0) {
            $this->stdout("{$counts['quotaBlocked']} rows are waiting on quota and will go out when it resets.\n", Console::FG_YELLOW);
        }

        return ExitCode::OK;
    }

    /**
     * What is waiting, and what would stop it going out.
     */
    public function actionStatus(): int
    {
        $plugin = Plugin::getInstance();

        foreach ($plugin->dispatcher->status() as $engine) {
            $state = match (true) {
                !$engine['enabled'] => 'off',
                !$engine['configured'] => 'not configured',
                $engine['dryRun'] => 'dry run',
                default => 'ready',
            };

            $this->stdout(str_pad((string)$engine['label'], 28), Console::FG_CYAN);
            $this->stdout(str_pad($state, 18));
            $this->stdout(sprintf('%4d pending', $engine['pending']));

            if ($engine['quota'] !== null) {
                $this->stdout(sprintf('   quota %d/%d (%s)', $engine['quotaUsed'], $engine['quota'], $engine['quotaDate']));
            }

            $this->stdout("\n");

            foreach ($engine['problems'] as $problem) {
                $this->stdout("    · {$problem}\n", Console::FG_YELLOW);
            }
        }

        return ExitCode::OK;
    }

    /**
     * Put every failed row back in the queue.
     */
    public function actionRetryFailed(): int
    {
        $plugin = Plugin::getInstance();
        $count = 0;

        /** @var SubmissionRecord $row */
        foreach (SubmissionRecord::find()->where(['status' => SubmissionRecord::STATUS_FAILED])->each(100) as $row) {
            $plugin->submissions->requeue($row);
            $count++;
        }

        $this->stdout("{$count} rows requeued.\n", Console::FG_GREEN);

        return ExitCode::OK;
    }

    /**
     * Queue everything that changed recently. Pro.
     */
    public function actionSweep(): int
    {
        $plugin = Plugin::getInstance();

        if (!Edition::allowsSweeps($plugin->isPro())) {
            $this->stderr("Sweeps are a Pro feature.\n", Console::FG_RED);

            return ExitCode::UNAVAILABLE;
        }

        $job = new SweepJob();
        $job->window = $this->limit;
        $job->execute(\Craft::$app->getQueue());

        $this->stdout("Sweep complete. Run sanka/queue/drain to send what it queued.\n", Console::FG_GREEN);

        return ExitCode::OK;
    }
}
