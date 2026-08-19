<?php

declare(strict_types=1);

namespace justinholtweb\sanka\engines;

use justinholtweb\sanka\models\SubmissionResult;

/**
 * A place URLs can be submitted to.
 *
 * Every engine answers the same six questions — am I usable, what notification types do I take, how
 * many URLs fit in one call, what is my daily allowance, when does that allowance reset, and what
 * happened — so the dispatcher contains no engine-specific code at all.
 */
interface EngineInterface
{
    public function handle(): string;

    public function label(): string;

    /** A sentence for the settings screen: what submitting here actually reaches. */
    public function description(): string;

    /** Whether the operator has switched it on. */
    public function isEnabled(): bool;

    /** Whether it has everything it needs to make a call. */
    public function isConfigured(): bool;

    /**
     * Everything standing between this engine and a working submission, phrased for a human.
     *
     * @return list<string>
     */
    public function problems(): array;

    public function supportsType(string $type): bool;

    /** How many URLs may go in one call. */
    public function maxPerCall(): int;

    /** Null means the engine publishes no daily limit. */
    public function dailyQuota(): ?int;

    /**
     * The engine's own idea of today, as `Y-m-d`.
     *
     * Google's quota resets at midnight Pacific whatever the server's clock says, so this is asked
     * of the engine rather than derived from the server date.
     */
    public function quotaDate(): string;

    /**
     * Submit a batch.
     *
     * Must return one result per URL passed in, keyed by URL, even when the whole call failed —
     * every ledger row needs its own answer.
     *
     * @param list<string> $urls
     * @return array<string, SubmissionResult>
     */
    public function submit(array $urls, string $type): array;
}
