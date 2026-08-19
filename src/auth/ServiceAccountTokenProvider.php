<?php

declare(strict_types=1);

namespace justinholtweb\sanka\auth;

use justinholtweb\sanka\errors\AuthException;
use justinholtweb\sanka\http\HttpClientInterface;

/**
 * Exchanges a signed service account assertion for an access token.
 *
 * This is the two-legged OAuth flow Google recommends for server-to-server
 * write access: no browser round trip, no refresh token to expire, and no
 * third-party plugin in the chain.
 */
final class ServiceAccountTokenProvider implements TokenProviderInterface
{
    public const SCOPE_INDEXING = 'https://www.googleapis.com/auth/indexing';

    private ?AccessToken $token = null;

    /** @var callable(): int */
    private $clock;

    /** @var callable(string): string|null */
    private $signer;

    /**
     * @param callable(): int|null $clock overridable so token expiry is testable
     * @param callable(string): string|null $signer overridable so tests need no RSA key
     */
    public function __construct(
        private readonly ServiceAccountCredentials $credentials,
        private readonly HttpClientInterface $http,
        private readonly string $scope = self::SCOPE_INDEXING,
        ?callable $clock = null,
        ?callable $signer = null,
    ) {
        $this->clock = $clock ?? static fn(): int => time();
        $this->signer = $signer;
    }

    public function getToken(): AccessToken
    {
        $now = ($this->clock)();

        if ($this->token !== null && !$this->token->isExpired($now)) {
            return $this->token;
        }

        return $this->token = $this->requestToken($now);
    }

    public function fingerprint(): string
    {
        return 'sa_' . $this->credentials->fingerprint();
    }

    /**
     * The signed assertion sent to Google's token endpoint. Exposed so the
     * console diagnostics can prove signing works without a network call.
     */
    public function buildAssertion(int $now): string
    {
        $claims = Jwt::claimSet(
            $this->credentials->clientEmail,
            $this->scope,
            $this->credentials->tokenUri,
            $now,
        );

        $signer = $this->signer ?? Jwt::rs256Signer($this->credentials->privateKey);

        return Jwt::encode(['alg' => 'RS256', 'typ' => 'JWT'], $claims, $signer);
    }

    private function requestToken(int $now): AccessToken
    {
        $body = http_build_query([
            'grant_type' => Jwt::GRANT_TYPE,
            'assertion' => $this->buildAssertion($now),
        ]);

        $response = $this->http->request(
            'POST',
            $this->credentials->tokenUri,
            ['Content-Type' => 'application/x-www-form-urlencoded'],
            $body,
        );

        if (!$response->isSuccessful()) {
            throw new AuthException($this->describeFailure($response->statusCode, $response->json()));
        }

        return AccessToken::fromResponse($response->json(), $now);
    }

    /**
     * @param array<string, mixed> $body
     */
    private function describeFailure(int $status, array $body): string
    {
        // The token endpoint answers with `{"error": "invalid_grant"}`, but a
        // proxy or an outage can hand back Google's `{"error": {"message": …}}`
        // shape instead.
        $error = $body['error'] ?? '';
        $description = (string)($body['error_description'] ?? '');

        if (is_array($error)) {
            $description = $description !== '' ? $description : (string)($error['message'] ?? '');
            $error = (string)($error['status'] ?? '');
        }

        $error = (string)$error;

        $message = "Google rejected the service account credentials (HTTP {$status})";

        if ($error !== '') {
            $message .= ": {$error}";
        }

        if ($description !== '') {
            $message .= " — {$description}";
        }

        if ($error === 'invalid_grant') {
            $message = rtrim($message, '.') . '. This usually means the server clock is out of sync or the key has been revoked';
        }

        return rtrim($message, '.') . '.';
    }
}
