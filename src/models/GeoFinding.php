<?php

declare(strict_types=1);

namespace justinholtweb\sanka\models;

/**
 * One thing the readiness audit checked.
 *
 * Findings are remediation-first: the message says what is true, and `remediation` says what to do
 * about it. A check that cannot say what to do about a failure is not worth running, so every
 * failing finding carries one.
 */
final class GeoFinding
{
    public const PASS = 'pass';
    public const WARN = 'warn';
    public const FAIL = 'fail';

    public const DIMENSION_STRUCTURE = 'structure';
    public const DIMENSION_ATTRIBUTION = 'attribution';
    public const DIMENSION_MACHINE = 'machine';
    public const DIMENSION_CITABILITY = 'citability';

    public const DIMENSIONS = [
        self::DIMENSION_STRUCTURE,
        self::DIMENSION_ATTRIBUTION,
        self::DIMENSION_MACHINE,
        self::DIMENSION_CITABILITY,
    ];

    public function __construct(
        public readonly string $id,
        public readonly string $dimension,
        public readonly string $label,
        public readonly string $status,
        public readonly string $message,
        public readonly string $remediation = '',
        /** Relative importance within its dimension. */
        public readonly int $weight = 1,
    ) {
    }

    public static function pass(string $id, string $dimension, string $label, string $message, int $weight = 1): self
    {
        return new self($id, $dimension, $label, self::PASS, $message, '', $weight);
    }

    public static function warn(string $id, string $dimension, string $label, string $message, string $remediation, int $weight = 1): self
    {
        return new self($id, $dimension, $label, self::WARN, $message, $remediation, $weight);
    }

    public static function fail(string $id, string $dimension, string $label, string $message, string $remediation, int $weight = 1): self
    {
        return new self($id, $dimension, $label, self::FAIL, $message, $remediation, $weight);
    }

    /** A partial credit of a half is deliberate: a warning is a real cost, not a rounding error. */
    public function credit(): float
    {
        return match ($this->status) {
            self::PASS => 1.0,
            self::WARN => 0.5,
            default => 0.0,
        };
    }
}
