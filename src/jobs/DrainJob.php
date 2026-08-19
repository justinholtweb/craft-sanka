<?php

declare(strict_types=1);

namespace justinholtweb\sanka\jobs;

use Craft;
use craft\queue\BaseJob;
use justinholtweb\sanka\Plugin;

/**
 * Drain the submission queue.
 *
 * Pushed after a save rather than submitting inline, because an author saving an entry should not
 * wait on Google, and because a failed submission must not be able to fail the save that caused it.
 */
class DrainJob extends BaseJob
{
    public ?string $engine = null;

    public ?int $limit = null;

    public function execute($queue): void
    {
        $counts = Plugin::getInstance()->dispatcher->drain($this->engine, $this->limit);

        Craft::info(sprintf(
            'Sanka drained the queue: %d considered, %d sent, %d skipped, %d retrying, %d failed.',
            $counts['considered'],
            $counts['sent'],
            $counts['skipped'],
            $counts['retrying'],
            $counts['failed'],
        ), Plugin::LOG_CATEGORY);
    }

    protected function defaultDescription(): string
    {
        return Craft::t('sanka', 'Submitting URLs to search engines');
    }
}
