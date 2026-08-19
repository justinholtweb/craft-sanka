<?php

declare(strict_types=1);

namespace justinholtweb\sanka\errors;

use Throwable;

/**
 * Raised when an engine returns something Sanka cannot treat as a submission outcome.
 *
 * Ordinary rejections are *not* exceptions — a 403 from IndexNow is a recorded result on a ledger
 * row, because the operator needs to see it next to the URL it belongs to. This is for the cases
 * where there is no row to write the answer onto.
 */
class EngineException extends SankaException
{
    public function __construct(
        string $message,
        public readonly int $statusCode = 0,
        public readonly ?string $reason = null,
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, $statusCode, $previous);
    }
}
