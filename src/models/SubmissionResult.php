<?php

declare(strict_types=1);

namespace justinholtweb\sanka\models;

use justinholtweb\sanka\records\SubmissionRecord;

/**
 * What one engine said about one URL.
 *
 * The engines return these rather than throwing, because the answer belongs on the ledger row next
 * to the URL it is about. A 403 from IndexNow is not an exceptional condition; it is a fact about
 * that submission that the operator needs to be able to read six weeks later.
 */
final class SubmissionResult
{
    private function __construct(
        /** One of {@see SubmissionRecord}'s statuses. */
        public readonly string $status,
        public readonly int $statusCode,
        public readonly string $message,
        /** Only meaningful when the status is `failed`. */
        public readonly bool $retryable = false,
    ) {
    }

    public static function sent(int $statusCode = 200, string $message = ''): self
    {
        return new self(SubmissionRecord::STATUS_SENT, $statusCode, $message);
    }

    /** Deliberately not sent. Costs no quota and is not retried. */
    public static function skipped(string $message, int $statusCode = 0): self
    {
        return new self(SubmissionRecord::STATUS_SKIPPED, $statusCode, $message);
    }

    /** Rejected in a way no retry could improve — a bad key, a URL on the wrong host, a 400. */
    public static function failed(string $message, int $statusCode = 0): self
    {
        return new self(SubmissionRecord::STATUS_FAILED, $statusCode, $message);
    }

    /** Failed, but the same request could plausibly succeed later — a 429, a 5xx, a timeout. */
    public static function retry(string $message, int $statusCode = 0): self
    {
        return new self(SubmissionRecord::STATUS_FAILED, $statusCode, $message, true);
    }

    public function isSent(): bool
    {
        return $this->status === SubmissionRecord::STATUS_SENT;
    }

    /** Whether this outcome consumed one of the engine's daily allowance. */
    public function costsQuota(): bool
    {
        // A rejection Google actually processed still counts against the project quota; a request
        // that never reached the engine does not.
        return $this->isSent() || ($this->statusCode >= 400 && $this->statusCode < 500 && $this->statusCode !== 401);
    }
}
