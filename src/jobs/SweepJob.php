<?php

declare(strict_types=1);

namespace justinholtweb\sanka\jobs;

use Craft;
use craft\elements\Entry;
use craft\helpers\Db;
use craft\helpers\Queue;
use craft\queue\BaseJob;
use DateTime;
use justinholtweb\sanka\models\Rule;
use justinholtweb\sanka\Plugin;
use justinholtweb\sanka\records\SubmissionRecord;

/**
 * Queue everything that changed recently and was not submitted at the time.
 *
 * The safety net for the cases the save hook cannot see: a deployment that ran `resave`, a queue
 * that was down, an import, a scheduled entry going live on its post date with nobody saving
 * anything. The window is its own bookmark — “changed in the last N seconds” needs no state to
 * persist and cannot get stuck on a run that died half way.
 *
 * Cooldown and deduplication in the ledger mean an overlapping sweep costs nothing.
 */
class SweepJob extends BaseJob
{
    /** Seconds back to look. Defaults to the configured sweep window. */
    public ?int $window = null;

    public string $reason = 'sweep';

    public function execute($queue): void
    {
        $plugin = Plugin::getInstance();
        $settings = $plugin->getSettings();
        $window = $this->window ?? $settings->sweepWindow;
        $since = Db::prepareDateForDb((new DateTime())->modify('-' . max(60, $window) . ' seconds'));

        $rules = $plugin->rules->all();
        $sections = [];
        $wantsEverything = false;

        foreach ($rules as $rule) {
            if (!$rule->handlesEvent(Rule::EVENT_UPDATE)) {
                continue;
            }

            if ($rule->section === Rule::ANY) {
                $wantsEverything = true;

                break;
            }

            $sections[] = $rule->section;
        }

        if (!$wantsEverything && $sections === []) {
            return;
        }

        $query = Entry::find()
            ->status(Entry::STATUS_LIVE)
            ->siteId('*')
            ->unique()
            ->andWhere(['>=', 'elements.dateUpdated', $since]);

        if (!$wantsEverything) {
            $query->section(array_values(array_unique($sections)));
        }

        $expected = max(1, (int)$query->count());
        $queued = 0;
        $total = 0;

        foreach ($query->each(100) as $entry) {
            $total++;
            $this->setProgress($queue, min(1.0, $total / $expected));

            $engines = $plugin->rules->enginesFor($entry, Rule::EVENT_UPDATE);

            if ($engines === null || $engines === []) {
                continue;
            }

            $queued += count($plugin->submissions->queueElement($entry, SubmissionRecord::TYPE_UPDATED, $this->reason, $engines));
        }

        Craft::info("Sanka swept {$total} recently changed entries and queued {$queued} submissions.", Plugin::LOG_CATEGORY);

        if ($queued > 0) {
            Queue::push(new DrainJob());
        }
    }

    protected function defaultDescription(): string
    {
        return Craft::t('sanka', 'Sweeping recently changed entries');
    }
}
