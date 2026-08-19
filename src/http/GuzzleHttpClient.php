<?php

declare(strict_types=1);

namespace justinholtweb\sanka\http;

use GuzzleHttp\Client;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Exception\RequestException;

/**
 * The production transport, backed by the Guzzle client Craft already ships.
 */
final class GuzzleHttpClient implements HttpClientInterface
{
    public function __construct(
        private readonly ClientInterface $client = new Client(),
        private readonly float $timeout = 15.0,
    ) {
    }

    public function request(string $method, string $url, array $headers = [], ?string $body = null): HttpResponse
    {
        $options = [
            'headers' => $headers,
            'timeout' => $this->timeout,
            'connect_timeout' => min($this->timeout, 10.0),
            'http_errors' => false,
        ];

        if ($body !== null) {
            $options['body'] = $body;
        }

        try {
            $response = $this->client->request($method, $url, $options);

            return new HttpResponse(
                $response->getStatusCode(),
                (string)$response->getBody(),
                self::flattenHeaders($response->getHeaders()),
            );
        } catch (ConnectException $e) {
            // No response at all — surface it as a 503 so callers can treat a
            // network blip the same way they treat an upstream outage.
            return new HttpResponse(503, json_encode([
                'error' => ['message' => $e->getMessage(), 'status' => 'UNAVAILABLE'],
            ]) ?: '');
        } catch (RequestException $e) {
            $response = $e->getResponse();

            if ($response !== null) {
                return new HttpResponse(
                    $response->getStatusCode(),
                    (string)$response->getBody(),
                    self::flattenHeaders($response->getHeaders()),
                );
            }

            return new HttpResponse(0, json_encode([
                'error' => ['message' => $e->getMessage(), 'status' => 'UNKNOWN'],
            ]) ?: '');
        }
    }

    /**
     * @param array<string, list<string>> $headers
     * @return array<string, string>
     */
    private static function flattenHeaders(array $headers): array
    {
        $flat = [];

        foreach ($headers as $name => $values) {
            $flat[strtolower((string)$name)] = implode(', ', $values);
        }

        return $flat;
    }
}
