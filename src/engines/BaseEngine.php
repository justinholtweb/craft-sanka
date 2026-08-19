<?php

declare(strict_types=1);

namespace justinholtweb\sanka\engines;

use DateTimeImmutable;
use DateTimeZone;
use justinholtweb\sanka\http\HttpClientInterface;
use justinholtweb\sanka\models\Settings;
use justinholtweb\sanka\models\SubmissionResult;

/**
 * What every engine shares: the transport, the settings, and the two helpers that keep the
 * dispatcher honest.
 */
abstract class BaseEngine implements EngineInterface
{
    public function __construct(
        protected readonly Settings $settings,
        protected readonly HttpClientInterface $http,
    ) {
    }

    public function isConfigured(): bool
    {
        return $this->problems() === [];
    }

    public function supportsType(string $type): bool
    {
        return true;
    }

    public function dailyQuota(): ?int
    {
        return null;
    }

    public function quotaDate(): string
    {
        return (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format('Y-m-d');
    }

    /**
     * Give every URL in a batch the same answer.
     *
     * Used whenever the failure is about the call rather than about any one URL — a rejected key,
     * an unreachable endpoint, a missing credential. Without this, a failed batch would write rows
     * with no result on them, which is the one thing the ledger must never contain.
     *
     * @param list<string> $urls
     * @return array<string, SubmissionResult>
     */
    protected function sameForAll(array $urls, SubmissionResult $result): array
    {
        $out = [];

        foreach ($urls as $url) {
            $out[$url] = $result;
        }

        return $out;
    }

    /**
     * The `Y-m-d` in a named time zone, for engines whose quota does not roll over at UTC midnight.
     */
    protected function dateIn(string $timezone): string
    {
        return (new DateTimeImmutable('now', new DateTimeZone($timezone)))->format('Y-m-d');
    }
}
