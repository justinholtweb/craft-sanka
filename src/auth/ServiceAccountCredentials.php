<?php

declare(strict_types=1);

namespace justinholtweb\sanka\auth;

use justinholtweb\sanka\errors\AuthException;

/**
 * A parsed Google service account key.
 *
 * Accepts the JSON exactly as Google Cloud downloads it, either inline or as a
 * file path, and validates the three fields that actually matter before any
 * network call — a clear "your key is missing client_email" beats a 400 from
 * Google an hour later.
 */
final class ServiceAccountCredentials
{
    public const DEFAULT_TOKEN_URI = 'https://oauth2.googleapis.com/token';

    private function __construct(
        public readonly string $clientEmail,
        public readonly string $privateKey,
        public readonly string $tokenUri,
        public readonly ?string $projectId = null,
    ) {
    }

    /**
     * @param array<string, mixed> $data
     * @throws AuthException if a required field is missing or empty
     */
    public static function fromArray(array $data): self
    {
        $clientEmail = trim((string)($data['client_email'] ?? ''));
        $privateKey = (string)($data['private_key'] ?? '');

        if ($clientEmail === '') {
            throw new AuthException('The service account JSON is missing "client_email".');
        }

        if (trim($privateKey) === '') {
            throw new AuthException('The service account JSON is missing "private_key".');
        }

        // Keys pasted through .env files or YAML often arrive with literal "\n"
        // instead of real newlines, which OpenSSL rejects outright.
        $privateKey = str_replace('\\n', "\n", $privateKey);

        $tokenUri = trim((string)($data['token_uri'] ?? ''));

        return new self(
            $clientEmail,
            $privateKey,
            $tokenUri !== '' ? $tokenUri : self::DEFAULT_TOKEN_URI,
            isset($data['project_id']) ? (string)$data['project_id'] : null,
        );
    }

    /**
     * @throws AuthException if the string is not valid service account JSON
     */
    public static function fromJson(string $json): self
    {
        $decoded = json_decode(trim($json), true);

        if (!is_array($decoded)) {
            throw new AuthException('The service account credentials are not valid JSON.');
        }

        if (($decoded['type'] ?? 'service_account') !== 'service_account') {
            throw new AuthException('The supplied credentials are not a service account key. Download a service account JSON key from Google Cloud.');
        }

        return self::fromArray($decoded);
    }

    /**
     * @throws AuthException if the file is missing or unreadable
     */
    public static function fromFile(string $path): self
    {
        if (!is_file($path) || !is_readable($path)) {
            throw new AuthException("The service account key file could not be read: {$path}");
        }

        $contents = file_get_contents($path);

        if ($contents === false) {
            throw new AuthException("The service account key file could not be read: {$path}");
        }

        return self::fromJson($contents);
    }

    /**
     * Resolve credentials from a setting that may hold either raw JSON or a
     * path to a JSON file.
     *
     * @throws AuthException if the value is empty or cannot be resolved
     */
    public static function resolve(?string $value): self
    {
        $value = $value !== null ? trim($value) : '';

        if ($value === '') {
            throw new AuthException('No Google service account credentials are configured.');
        }

        if (str_starts_with($value, '{')) {
            return self::fromJson($value);
        }

        return self::fromFile($value);
    }

    /**
     * A non-secret identifier for these credentials.
     */
    public function fingerprint(): string
    {
        return substr(sha1($this->clientEmail), 0, 12);
    }
}
