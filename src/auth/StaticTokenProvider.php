<?php

declare(strict_types=1);

namespace justinholtweb\sanka\auth;

/**
 * Returns a token that was obtained elsewhere.
 *
 * Used by the test suite, and by installations that mint tokens with their own
 * tooling and inject one at runtime.
 */
final class StaticTokenProvider implements TokenProviderInterface
{
    public function __construct(private readonly AccessToken $token)
    {
    }

    public static function of(string $value, ?int $expiresAt = null): self
    {
        return new self(new AccessToken($value, $expiresAt ?? (time() + 3600)));
    }

    public function getToken(): AccessToken
    {
        return $this->token;
    }

    public function fingerprint(): string
    {
        return 'static_' . substr(sha1($this->token->value), 0, 12);
    }
}
