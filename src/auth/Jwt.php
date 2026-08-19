<?php

declare(strict_types=1);

namespace justinholtweb\sanka\auth;

use justinholtweb\sanka\errors\AuthException;

/**
 * The slice of JWT needed to mint a Google service account assertion.
 *
 * Signing is passed in as a callable so the claim set and encoding can be
 * asserted without a real RSA key, and so the one place that touches OpenSSL
 * stays a single line.
 */
final class Jwt
{
    public const GRANT_TYPE = 'urn:ietf:params:oauth:grant-type:jwt-bearer';

    /**
     * Maximum assertion lifetime Google accepts.
     */
    public const MAX_LIFETIME = 3600;

    public static function base64UrlEncode(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }

    public static function base64UrlDecode(string $value): string
    {
        $padded = str_pad(strtr($value, '-_', '+/'), (int)(ceil(strlen($value) / 4) * 4), '=');

        return base64_decode($padded, true) ?: '';
    }

    /**
     * The claim set for a service account assertion.
     *
     * @return array<string, mixed>
     */
    public static function claimSet(
        string $issuer,
        string $scope,
        string $audience,
        int $now,
        int $lifetime = self::MAX_LIFETIME,
    ): array {
        if (trim($issuer) === '') {
            throw new AuthException('The service account is missing its client email.');
        }

        return [
            'iss' => $issuer,
            'scope' => $scope,
            'aud' => $audience,
            'exp' => $now + min(max($lifetime, 1), self::MAX_LIFETIME),
            'iat' => $now,
        ];
    }

    /**
     * Encode and sign a JWT.
     *
     * @param array<string, mixed> $header
     * @param array<string, mixed> $claims
     * @param callable(string): string $signer receives the signing input and
     *        returns the raw (unencoded) signature
     */
    public static function encode(array $header, array $claims, callable $signer): string
    {
        $segments = [
            self::base64UrlEncode((string)json_encode($header)),
            self::base64UrlEncode((string)json_encode($claims)),
        ];

        $signingInput = implode('.', $segments);
        $signature = $signer($signingInput);

        if ($signature === '') {
            throw new AuthException('Failed to sign the service account assertion.');
        }

        return $signingInput . '.' . self::base64UrlEncode($signature);
    }

    /**
     * Sign with an RS256 private key, the only algorithm Google accepts for
     * service account assertions.
     *
     * @param string $privateKey PEM-encoded private key
     */
    public static function rs256Signer(string $privateKey): callable
    {
        return static function(string $input) use ($privateKey): string {
            $key = openssl_pkey_get_private($privateKey);

            if ($key === false) {
                throw new AuthException('The service account private key could not be read. Check that the JSON key is intact and its newlines were not mangled.');
            }

            $signature = '';

            if (!openssl_sign($input, $signature, $key, OPENSSL_ALGO_SHA256)) {
                throw new AuthException('Could not sign the service account assertion with the supplied private key.');
            }

            return $signature;
        };
    }
}
