<?php

declare(strict_types=1);

namespace justinholtweb\sanka\auth;

use justinholtweb\sanka\errors\AuthException;

/**
 * Supplies bearer tokens for the Google Indexing API.
 *
 * A service account is the only credential Google accepts for the Indexing API — there is no
 * browser round trip and no refresh token — so one real implementation ships, plus a static
 * provider for tests and for setups where the token is minted elsewhere.
 */
interface TokenProviderInterface
{
    /**
     * @throws AuthException when a usable token cannot be obtained
     */
    public function getToken(): AccessToken;

    /**
     * A short identifier for the credentials in use, safe to log and to mix
     * into cache keys. Must never contain the secret itself.
     */
    public function fingerprint(): string;
}
