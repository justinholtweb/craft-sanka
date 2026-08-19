<?php

declare(strict_types=1);

namespace justinholtweb\sanka\http;

/**
 * The transport seam for every outbound request the plugin makes.
 *
 * Implementations must return a response for non-2xx statuses rather than
 * throwing, so error handling lives in one place (the callers) instead of being
 * split between exception handlers and status checks.
 */
interface HttpClientInterface
{
    /**
     * @param array<string, string> $headers
     */
    public function request(string $method, string $url, array $headers = [], ?string $body = null): HttpResponse;
}
