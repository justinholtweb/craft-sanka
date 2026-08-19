<?php

declare(strict_types=1);

namespace justinholtweb\sanka\services;

use Craft;
use craft\base\Component;
use justinholtweb\sanka\engines\EngineInterface;
use justinholtweb\sanka\models\SubmissionResult;
use justinholtweb\sanka\Plugin;
use justinholtweb\sanka\records\SubmissionRecord;
use Throwable;

/**
 * The only thing in Sanka that calls an engine.
 *
 * It contains no engine-specific code at all — every engine answers the same six questions, so the
 * drain loop is the same loop for Google, IndexNow and sitemaps. Adding a fourth engine means
 * writing an engine, not touching this file.
 *
 * Two invariants it exists to hold:
 *
 * - **Quota is spent knowingly.** The remaining allowance is read before the batch is assembled and
 *   the batch is cut to fit it. Nothing here discovers an exhausted quota by being refused.
 * - **Every row gets an answer.** A crashed call, a missing batch part, an engine that returned
 *   fewer results than URLs — all of them end with a result written to every row that was in the
 *   batch. A row that went out and came back with nothing on it is the one state the ledger is not
 *   allowed to contain.
 */
class Dispatcher extends Component
{
    /**
     * Drain the queue.
     *
     * @param string|null $engineHandle limit to one engine
     * @param int|null $limit rows to take across all engines; defaults to the configured drain limit
     * @return array<string, int> counts by outcome, plus `quotaBlocked`
     */
    public function drain(?string $engineHandle = null, ?int $limit = null): array
    {
        $plugin = Plugin::getInstance();
        $limit ??= $plugin->getSettings()->drainLimit;

        $engines = $plugin->engines->getUsable();

        if ($engineHandle !== null) {
            $engines = array_filter($engines, static fn(EngineInterface $e): bool => $e->handle() === $engineHandle);
        }

        $totals = ['considered' => 0, 'sent' => 0, 'failed' => 0, 'skipped' => 0, 'retrying' => 0, 'quotaBlocked' => 0];

        foreach ($engines as $engine) {
            if ($limit <= 0) {
                break;
            }

            $counts = $this->drainEngine($engine, $limit);

            foreach ($counts as $key => $value) {
                $totals[$key] += $value;
            }

            $limit -= $counts['considered'];
        }

        return $totals;
    }

    /**
     * How much work is waiting, and what would stop it going out right now.
     *
     * Used by the overview screen and by the diagnostics command, so “nothing is being submitted”
     * has an answer that does not require reading the queue by hand.
     *
     * @return list<array<string, mixed>>
     */
    public function status(): array
    {
        $plugin = Plugin::getInstance();
        $settings = $plugin->getSettings();
        $rows = [];

        foreach ($plugin->engines->getAvailable() as $engine) {
            $remaining = $plugin->quota->remaining($engine);

            $rows[] = [
                'handle' => $engine->handle(),
                'label' => $engine->label(),
                'description' => $engine->description(),
                'enabled' => $engine->isEnabled(),
                'configured' => $engine->isConfigured(),
                'problems' => $engine->problems(),
                'pending' => $plugin->submissions->pendingCount($engine->handle()),
                'quota' => $engine->dailyQuota(),
                'quotaUsed' => $engine->dailyQuota() === null ? null : $plugin->quota->used($engine->handle(), $engine->quotaDate()),
                'quotaRemaining' => $remaining,
                'quotaDate' => $engine->quotaDate(),
                'dryRun' => $settings->dryRun,
            ];
        }

        return $rows;
    }

    // ----------------------------------------------------------------- private

    /**
     * @return array<string, int>
     */
    private function drainEngine(EngineInterface $engine, int $limit): array
    {
        $plugin = Plugin::getInstance();
        $counts = ['considered' => 0, 'sent' => 0, 'failed' => 0, 'skipped' => 0, 'retrying' => 0, 'quotaBlocked' => 0];
        $remaining = $plugin->quota->remaining($engine);

        // An engine call carries exactly one notification type, so a mixed queue drains as one call
        // per type rather than one call per drain.
        foreach ($plugin->submissions->pendingTypes($engine->handle()) as $type) {
            if ($limit <= 0) {
                break;
            }

            if (!$engine->supportsType($type)) {
                continue;
            }

            $take = min($limit, $engine->maxPerCall());

            if ($remaining !== null) {
                $take = min($take, $remaining);
            }

            if ($take <= 0) {
                $counts['quotaBlocked'] += $plugin->submissions->pendingCount($engine->handle());

                break;
            }

            $rows = $plugin->submissions->pending($engine->handle(), $type, $take);

            if ($rows === []) {
                continue;
            }

            $spent = $this->send($engine, $rows, $type, $counts);

            $counts['considered'] += count($rows);
            $limit -= count($rows);

            if ($remaining !== null) {
                $remaining = max(0, $remaining - $spent);
            }
        }

        return $counts;
    }

    /**
     * @param list<SubmissionRecord> $rows
     * @param array<string, int> $counts
     * @return int how much quota the call spent
     */
    private function send(EngineInterface $engine, array $rows, string $type, array &$counts): int
    {
        $plugin = Plugin::getInstance();

        if ($plugin->getSettings()->dryRun) {
            $result = SubmissionResult::skipped(Craft::t('sanka', 'Dry run — nothing was sent. Turn dry run off in Sanka’s settings when you are ready.'));

            foreach ($rows as $row) {
                $plugin->submissions->record($row, $result);
                $counts['skipped']++;
            }

            return 0;
        }

        $urls = array_values(array_map(static fn(SubmissionRecord $r): string => (string)$r->url, $rows));

        try {
            $results = $engine->submit($urls, $type);
        } catch (Throwable $e) {
            // An engine that throws is a bug in the engine, not a fact about these URLs. Every row
            // gets the same retryable answer so nothing is lost and the exception is on record.
            Craft::error("Sanka: {$engine->handle()} threw while submitting: {$e->getMessage()}", Plugin::LOG_CATEGORY);

            $results = array_fill_keys($urls, SubmissionResult::retry(
                Craft::t('sanka', 'The submission failed unexpectedly: {message}', ['message' => $e->getMessage()]),
            ));
        }

        $spent = 0;

        foreach ($rows as $row) {
            $result = $results[(string)$row->url] ?? SubmissionResult::retry(
                Craft::t('sanka', 'The engine returned no answer for this URL.'),
            );

            $plugin->submissions->record($row, $result);

            if ($result->costsQuota()) {
                $spent++;
            }

            $counts[$this->bucket($row, $result)]++;
        }

        if ($spent > 0) {
            $plugin->quota->consume($engine->handle(), $engine->quotaDate(), $spent);
        }

        return $spent;
    }

    private function bucket(SubmissionRecord $row, SubmissionResult $result): string
    {
        if ($result->isSent()) {
            return 'sent';
        }

        if ($result->status === SubmissionRecord::STATUS_SKIPPED) {
            return 'skipped';
        }

        // `record()` has already decided whether this one goes back in the queue; reading the row
        // rather than the result is what keeps the reported count honest.
        return $row->status === SubmissionRecord::STATUS_PENDING ? 'retrying' : 'failed';
    }
}
