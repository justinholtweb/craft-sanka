<?php

declare(strict_types=1);

namespace justinholtweb\sanka\tests\support;

use justinholtweb\sanka\http\HttpClientInterface;
use justinholtweb\sanka\http\HttpResponse;

/**
 * The transport seam, standing in for the network.
 *
 * This is why the checks can exercise the whole submission path — dispatcher, ledger, quota,
 * backoff, batching, result interpretation — without a single outbound request. Every call is
 * recorded, so a check can assert on what Sanka *sent* as well as on what it did with the answer.
 */
final class FakeHttpClient implements HttpClientInterface
{
    /** @var list<array{method: string, url: string, headers: array<string, string>, body: string|null}> */
    public array $requests = [];

    /** @var callable(string, string, array<string, string>, string|null): HttpResponse */
    private $responder;

    /**
     * @param callable(string, string, array<string, string>, string|null): HttpResponse|null $responder
     */
    public function __construct(?callable $responder = null)
    {
        $this->responder = $responder ?? self::defaultResponder();
    }

    public function request(string $method, string $url, array $headers = [], ?string $body = null): HttpResponse
    {
        $this->requests[] = ['method' => $method, 'url' => $url, 'headers' => $headers, 'body' => $body];

        return ($this->responder)($method, $url, $headers, $body);
    }

    public function setResponder(callable $responder): void
    {
        $this->responder = $responder;
    }

    public function reset(): void
    {
        $this->requests = [];
    }

    /**
     * @return list<array{method: string, url: string, headers: array<string, string>, body: string|null}>
     */
    public function requestsTo(string $needle): array
    {
        return array_values(array_filter($this->requests, static fn(array $r): bool => str_contains($r['url'], $needle)));
    }

    public function lastRequest(): ?array
    {
        return $this->requests === [] ? null : $this->requests[count($this->requests) - 1];
    }

    /**
     * Answers the OAuth token endpoint so every other check can be about the thing it is testing,
     * and 200s everything else.
     */
    public static function defaultResponder(): callable
    {
        return static function(string $method, string $url, array $headers = [], ?string $body = null): HttpResponse {
            if (str_contains($url, 'oauth2.googleapis.com/token')) {
                return new HttpResponse(200, (string)json_encode([
                    'access_token' => 'fake-token',
                    'expires_in' => 3600,
                    'token_type' => 'Bearer',
                ]));
            }

            if (str_contains($url, 'indexing.googleapis.com/batch')) {
                return self::batchSuccess((string)$body);
            }

            return new HttpResponse(200, '{}');
        };
    }

    /**
     * A 200 for every part of a batch request, echoing back its Content-IDs.
     *
     * Built from the request rather than hard-coded: a batch response that did not answer the URLs
     * actually sent would make the dispatcher's checks pass for the wrong reason.
     */
    public static function batchSuccess(string $requestBody): HttpResponse
    {
        preg_match_all('/Content-ID:\s*<([^>]+)>/i', $requestBody, $matches);

        $boundary = 'batch_fake';
        $out = '';

        foreach ($matches[1] as $id) {
            $out .= "--{$boundary}\r\nContent-Type: application/http\r\nContent-ID: <response-{$id}>\r\n\r\n"
                . "HTTP/1.1 200 OK\r\nContent-Type: application/json\r\n\r\n{}\r\n";
        }

        $out .= "--{$boundary}--\r\n";

        return new HttpResponse(200, $out, ['content-type' => "multipart/mixed; boundary={$boundary}"]);
    }

    /**
     * A responder that answers the token endpoint and hands everything else a fixed status/body.
     */
    public static function fixed(int $status, string $body = '{}', array $headers = []): callable
    {
        $default = self::defaultResponder();

        return static function(string $method, string $url, array $requestHeaders, ?string $requestBody) use ($status, $body, $headers, $default): HttpResponse {
            if (str_contains($url, 'oauth2.googleapis.com/token')) {
                return $default($method, $url, $requestHeaders, $requestBody);
            }

            return new HttpResponse($status, $body, $headers);
        };
    }

    /**
     * A service account key that is real enough to sign with, generated on the spot so no private
     * key is ever committed to the repository.
     *
     * @return array{json: string, email: string}
     */
    public static function serviceAccount(): array
    {
        $key = openssl_pkey_new([
            'private_key_bits' => 2048,
            'private_key_type' => OPENSSL_KEYTYPE_RSA,
        ]);

        openssl_pkey_export($key, $pem);

        return [
            'json' => (string)json_encode([
                'type' => 'service_account',
                'project_id' => 'sanka-checks',
                'client_email' => 'sanka-checks@example.iam.gserviceaccount.com',
                'private_key' => $pem,
                'token_uri' => 'https://oauth2.googleapis.com/token',
            ]),
            'email' => 'sanka-checks@example.iam.gserviceaccount.com',
        ];
    }
}
