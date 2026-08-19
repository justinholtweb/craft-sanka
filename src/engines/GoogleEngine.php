<?php

declare(strict_types=1);

namespace justinholtweb\sanka\engines;

use Craft;
use justinholtweb\sanka\auth\ServiceAccountCredentials;
use justinholtweb\sanka\auth\ServiceAccountTokenProvider;
use justinholtweb\sanka\auth\TokenProviderInterface;
use justinholtweb\sanka\errors\AuthException;
use justinholtweb\sanka\http\HttpClientInterface;
use justinholtweb\sanka\models\Settings;
use justinholtweb\sanka\models\SubmissionResult;
use justinholtweb\sanka\records\SubmissionRecord;

/**
 * Google's Indexing API.
 *
 * Two things about it are worth knowing before reading the code, and both are said out loud on the
 * settings screen rather than buried here:
 *
 * 1. Google documents the API as being for pages carrying `JobPosting` or `BroadcastEvent` markup.
 *    In practice it is used far more widely. Sanka does not pretend otherwise in either direction —
 *    it works, and it is the operator's call to make knowingly.
 * 2. The default quota is **200 publishes per project per day**, resetting at midnight Pacific.
 *    That is small enough that spending it by accident is the normal failure mode, which is why
 *    quota is checked before the call and why cooldown exists at all.
 *
 * Batches are real multipart/mixed batches rather than a loop, because 100 URLs is 1 round trip
 * instead of 100. They cost the same quota either way — batching buys latency, not allowance.
 */
class GoogleEngine extends BaseEngine
{
    public const HANDLE = 'google';

    public const PUBLISH_URL = 'https://indexing.googleapis.com/v3/urlNotifications:publish';
    public const METADATA_URL = 'https://indexing.googleapis.com/v3/urlNotifications/metadata';
    public const BATCH_URL = 'https://indexing.googleapis.com/batch';

    /** Google's documented ceiling for one batch. */
    public const MAX_BATCH = 100;

    /** The quota day rolls over here, not wherever the server is. */
    public const QUOTA_TIMEZONE = 'America/Los_Angeles';

    private ?TokenProviderInterface $tokens = null;

    public function __construct(
        Settings $settings,
        HttpClientInterface $http,
        ?TokenProviderInterface $tokens = null,
    ) {
        parent::__construct($settings, $http);
        $this->tokens = $tokens;
    }

    public function handle(): string
    {
        return self::HANDLE;
    }

    public function label(): string
    {
        return Craft::t('sanka', 'Google Indexing API');
    }

    public function description(): string
    {
        return Craft::t('sanka', 'Tells Google directly that a URL was added, changed or removed. 200 submissions per day by default, and the service account must be an owner of the Search Console property.');
    }

    public function isEnabled(): bool
    {
        return $this->settings->googleEnabled;
    }

    public function problems(): array
    {
        $problems = [];

        if (trim($this->settings->googleCredentials) === '') {
            $problems[] = Craft::t('sanka', 'No service account key. Create one in Google Cloud with the Indexing API enabled, then paste its JSON here.');

            return $problems;
        }

        try {
            ServiceAccountCredentials::resolve($this->settings->googleCredentials);
        } catch (AuthException $e) {
            $problems[] = $e->getMessage();
        }

        return $problems;
    }

    public function maxPerCall(): int
    {
        return self::MAX_BATCH;
    }

    public function dailyQuota(): ?int
    {
        return max(1, $this->settings->googleDailyQuota);
    }

    public function quotaDate(): string
    {
        return $this->dateIn(self::QUOTA_TIMEZONE);
    }

    public function submit(array $urls, string $type): array
    {
        $urls = array_values(array_unique($urls));

        if ($urls === []) {
            return [];
        }

        try {
            $token = $this->tokenProvider()->getToken()->value;
        } catch (AuthException $e) {
            // No call was made, so nothing was spent — but it will keep failing until somebody
            // fixes the settings, so this is not something to retry into the ground.
            return $this->sameForAll($urls, SubmissionResult::failed($e->getMessage()));
        }

        $notification = $this->notificationType($type);

        if (count($urls) === 1) {
            return [$urls[0] => $this->publishOne($urls[0], $notification, $token)];
        }

        return $this->publishBatch($urls, $notification, $token);
    }

    /**
     * What Google last heard about a URL.
     *
     * Read-only and cheap, and it is the only way to tell “Google accepted my notification” from
     * “Google acted on it”. Returns null when Google has no record of the URL at all.
     *
     * @return array<string, mixed>|null
     * @throws AuthException
     */
    public function metadata(string $url): ?array
    {
        $token = $this->tokenProvider()->getToken()->value;

        $response = $this->http->request(
            'GET',
            self::METADATA_URL . '?url=' . rawurlencode($url),
            ['Authorization' => "Bearer {$token}", 'Accept' => 'application/json'],
        );

        if ($response->statusCode === 404) {
            return null;
        }

        if (!$response->isSuccessful()) {
            throw new AuthException($this->describe($response->statusCode, $response->json()));
        }

        return $response->json();
    }

    /**
     * The signed assertion, without making a network call.
     *
     * Exposed so the diagnostics command can prove the key parses and signs before anyone starts
     * debugging Google's answer to a request that was never validly formed.
     *
     * @throws AuthException
     */
    public function buildAssertion(int $now): string
    {
        $provider = $this->tokenProvider();

        if (!$provider instanceof ServiceAccountTokenProvider) {
            throw new AuthException('No service account is configured.');
        }

        return $provider->buildAssertion($now);
    }

    public function tokenProvider(): TokenProviderInterface
    {
        return $this->tokens ??= new ServiceAccountTokenProvider(
            ServiceAccountCredentials::resolve($this->settings->googleCredentials),
            $this->http,
            ServiceAccountTokenProvider::SCOPE_INDEXING,
        );
    }

    // ----------------------------------------------------------------- private

    private function notificationType(string $type): string
    {
        return $type === SubmissionRecord::TYPE_DELETED ? 'URL_DELETED' : 'URL_UPDATED';
    }

    private function publishOne(string $url, string $notification, string $token): SubmissionResult
    {
        $response = $this->http->request(
            'POST',
            self::PUBLISH_URL,
            [
                'Authorization' => "Bearer {$token}",
                'Content-Type' => 'application/json',
                'Accept' => 'application/json',
            ],
            (string)json_encode(['url' => $url, 'type' => $notification]),
        );

        return $this->interpret($response->statusCode, $response->json());
    }

    /**
     * @param list<string> $urls
     * @return array<string, SubmissionResult>
     */
    private function publishBatch(array $urls, string $notification, string $token): array
    {
        $boundary = 'sanka_' . bin2hex(random_bytes(12));
        $body = '';

        foreach ($urls as $i => $url) {
            $payload = (string)json_encode(['url' => $url, 'type' => $notification]);

            $body .= "--{$boundary}\r\n";
            $body .= "Content-Type: application/http\r\n";
            $body .= "Content-ID: <sanka-{$i}>\r\n\r\n";
            $body .= "POST /v3/urlNotifications:publish HTTP/1.1\r\n";
            $body .= "Content-Type: application/json\r\n";
            $body .= 'Content-Length: ' . strlen($payload) . "\r\n";
            $body .= "Accept: application/json\r\n\r\n";
            $body .= $payload . "\r\n";
        }

        $body .= "--{$boundary}--\r\n";

        $response = $this->http->request(
            'POST',
            self::BATCH_URL,
            [
                'Authorization' => "Bearer {$token}",
                'Content-Type' => "multipart/mixed; boundary={$boundary}",
            ],
            $body,
        );

        // A batch that fails as a whole — a bad token, an outage — comes back as ordinary JSON.
        if (!$response->isSuccessful()) {
            return $this->sameForAll($urls, $this->interpret($response->statusCode, $response->json()));
        }

        $parts = $this->parseBatchResponse($response->body, $response->header('content-type'));

        $results = [];

        foreach ($urls as $i => $url) {
            $part = $parts["sanka-{$i}"] ?? null;

            $results[$url] = $part === null
                // Google answered 200 for the batch but this part is missing. Retryable: the
                // notification demonstrably did not land, and nothing about the URL is wrong.
                ? SubmissionResult::retry(Craft::t('sanka', 'Google’s batch response had no answer for this URL.'), 200)
                : $this->interpret($part['status'], $part['body']);
        }

        return $results;
    }

    /**
     * Split a `multipart/mixed` batch response into `Content-ID => [status, body]`.
     *
     * Parts come back keyed by the `Content-ID` that was sent, prefixed with `response-`, and
     * Google does not promise to return them in order — so they are matched by ID, never by
     * position.
     *
     * @return array<string, array{status: int, body: array<string, mixed>}>
     */
    private function parseBatchResponse(string $body, ?string $contentType): array
    {
        $boundary = null;

        if ($contentType !== null && preg_match('/boundary=("?)([^";]+)\1/i', $contentType, $m)) {
            $boundary = $m[2];
        }

        if ($boundary === null) {
            // Fall back to the delimiter the body opens with — a multipart body always starts with
            // one, and relying on it means the parser survives a transport that drops headers.
            $firstLine = strtok($body, "\r\n");

            if (is_string($firstLine) && str_starts_with($firstLine, '--')) {
                $boundary = substr($firstLine, 2);
            }
        }

        if ($boundary === null) {
            return [];
        }

        $parts = [];

        foreach (explode('--' . $boundary, $body) as $chunk) {
            $chunk = trim($chunk, "\r\n");

            if ($chunk === '' || $chunk === '--') {
                continue;
            }

            if (!preg_match('/^Content-ID:\s*<?(?:response-)?([^>\r\n]+)>?/mi', $chunk, $idMatch)) {
                continue;
            }

            // Part headers, then the embedded HTTP response, then its headers, then its body.
            $sections = preg_split("/\r?\n\r?\n/", $chunk, 3);

            if ($sections === false || count($sections) < 2) {
                continue;
            }

            $status = 0;

            if (preg_match('#^HTTP/\d(?:\.\d)?\s+(\d{3})#', ltrim($sections[1]), $statusMatch)) {
                $status = (int)$statusMatch[1];
            }

            $decoded = isset($sections[2]) ? json_decode(trim($sections[2]), true) : null;

            $parts[trim($idMatch[1])] = [
                'status' => $status,
                'body' => is_array($decoded) ? $decoded : [],
            ];
        }

        return $parts;
    }

    /**
     * Turn one HTTP answer into a ledger outcome.
     *
     * @param array<string, mixed> $body
     */
    private function interpret(int $status, array $body): SubmissionResult
    {
        if ($status >= 200 && $status < 300) {
            return SubmissionResult::sent($status, Craft::t('sanka', 'Accepted by Google.'));
        }

        $message = $this->describe($status, $body);

        return match (true) {
            $status === 429, $status >= 500, $status === 0 => SubmissionResult::retry($message, $status),
            default => SubmissionResult::failed($message, $status),
        };
    }

    /**
     * Google's status codes cover several completely different problems each, so the raw message is
     * prefixed with the fix rather than left for the operator to look up.
     *
     * @param array<string, mixed> $body
     */
    private function describe(int $status, array $body): string
    {
        $error = is_array($body['error'] ?? null) ? $body['error'] : [];
        $detail = trim((string)($error['message'] ?? ''));

        $lead = match ($status) {
            401 => Craft::t('sanka', 'Google rejected the credentials. Check the service account key, and that the server clock is correct.'),
            403 => Craft::t('sanka', 'Permission denied. The service account must be added as an owner of this site’s property in Search Console, and the Indexing API must be enabled for the project.'),
            404 => Craft::t('sanka', 'Google has no record of that URL.'),
            429 => Craft::t('sanka', 'Daily quota exhausted. Google allows 200 publishes per project per day by default; it resets at midnight Pacific.'),
            400 => Craft::t('sanka', 'Google rejected the request. The URL must be absolute, publicly reachable, and on a property the service account owns.'),
            0 => Craft::t('sanka', 'Could not reach Google.'),
            default => Craft::t('sanka', 'Google returned HTTP {status}.', ['status' => $status]),
        };

        return $detail !== '' && $detail !== $lead ? "{$lead} ({$detail})" : $lead;
    }
}
