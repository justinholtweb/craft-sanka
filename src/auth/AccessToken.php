<?php

declare(strict_types=1);

namespace justinholtweb\sanka\auth;

use justinholtweb\sanka\errors\AuthException;

/**
 * An OAuth 2.0 bearer token with the moment it stops being usable.
 */
final class AccessToken
{
    /**
     * Refresh this many seconds before the real expiry, so a token never dies
     * mid-request because of clock skew or a slow round trip.
     */
    public const LEEWAY = 60;

    public function __construct(
        public readonly string $value,
        public readonly int $expiresAt,
    ) {
        if (trim($value) === '') {
            throw new AuthException('Received an empty access token.');
        }
    }

    /**
     * Build a token from a Google token endpoint response.
     *
     * @param array<string, mixed> $response
     */
    public static function fromResponse(array $response, int $now): self
    {
        $value = $response['access_token'] ?? null;

        if (!is_string($value) || trim($value) === '') {
            throw new AuthException('The token response did not contain an access token.');
        }

        // Google always sends expires_in, but defaulting to the documented one
        // hour keeps a missing field from producing a permanently stale token.
        $expiresIn = isset($response['expires_in']) ? (int)$response['expires_in'] : 3600;

        return new self($value, $now + max($expiresIn, 0));
    }

    public function isExpired(int $now, int $leeway = self::LEEWAY): bool
    {
        return ($this->expiresAt - $leeway) <= $now;
    }

    /**
     * Seconds this token can still be cached for, never negative.
     */
    public function secondsRemaining(int $now, int $leeway = self::LEEWAY): int
    {
        return max(0, $this->expiresAt - $leeway - $now);
    }
}
