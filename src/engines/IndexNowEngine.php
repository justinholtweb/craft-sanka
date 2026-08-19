<?php

declare(strict_types=1);

namespace justinholtweb\sanka\engines;

use Craft;
use craft\helpers\UrlHelper;
use justinholtweb\sanka\models\SubmissionResult;

/**
 * IndexNow.
 *
 * One submission reaches every participating engine — Microsoft Bing, Yandex, Seznam, Naver and
 * Yep — because they share submissions between themselves. That is why Sanka posts to one endpoint
 * rather than fanning out: fanning out would be the same submission counted five times against
 * five rate limits for no additional reach.
 *
 * It is also, in 2026, the most valuable half of this plugin. Bing's index is what ChatGPT Search
 * and Copilot answer from, so an IndexNow submission is the shortest path from “I published this”
 * to “an answer engine knows it exists”. Google does not participate.
 *
 * The key file is served from a route rather than written to the web root, so there is no file to
 * forget to deploy and nothing to go stale when the key is regenerated.
 */
class IndexNowEngine extends BaseEngine
{
    public const HANDLE = 'indexnow';

    public const DEFAULT_ENDPOINT = 'api.indexnow.org';

    /** @var array<string, string> host => label */
    public const ENDPOINTS = [
        'api.indexnow.org' => 'IndexNow (shared — reaches all of them)',
        'www.bing.com' => 'Microsoft Bing',
        'yandex.com' => 'Yandex',
        'search.seznam.cz' => 'Seznam.cz',
        'searchadvisor.naver.com' => 'Naver',
        'indexnow.yep.com' => 'Yep',
    ];

    /** The protocol's documented ceiling for one POST. */
    public const MAX_URLS = 10000;

    public function handle(): string
    {
        return self::HANDLE;
    }

    public function label(): string
    {
        return Craft::t('sanka', 'IndexNow');
    }

    public function description(): string
    {
        return Craft::t('sanka', 'One submission reaches Bing, Yandex, Seznam, Naver and Yep. Bing is what ChatGPT Search and Copilot answer from, so this is the route to the AI engines. Google does not participate.');
    }

    public function isEnabled(): bool
    {
        return $this->settings->indexNowEnabled;
    }

    public function problems(): array
    {
        $problems = [];
        $key = trim($this->settings->indexNowKey);

        if ($key === '') {
            $problems[] = Craft::t('sanka', 'No IndexNow key. Generate one — Sanka serves the key file itself, so there is nothing to upload.');
        } elseif (!preg_match('/^[A-Za-z0-9\-]{8,128}$/', $key)) {
            $problems[] = Craft::t('sanka', 'The IndexNow key must be 8–128 characters of letters, numbers and dashes.');
        }

        if (!isset(self::ENDPOINTS[$this->settings->indexNowEndpoint])) {
            $problems[] = Craft::t('sanka', 'Unknown IndexNow endpoint: {endpoint}', ['endpoint' => $this->settings->indexNowEndpoint]);
        }

        return $problems;
    }

    public function maxPerCall(): int
    {
        return self::MAX_URLS;
    }

    public function dailyQuota(): ?int
    {
        return max(1, $this->settings->indexNowDailyQuota);
    }

    /**
     * The URL the key file is served from for a given site.
     *
     * Derived from the site's own base URL rather than assembled from the host, so a Craft install
     * in a subdirectory gets a key file inside that subdirectory — which is also exactly the scope
     * IndexNow will then allow the key to submit for.
     */
    public function keyUrl(?int $siteId = null): string
    {
        return UrlHelper::siteUrl(trim($this->settings->indexNowKey) . '.txt', null, null, $siteId);
    }

    public function submit(array $urls, string $type): array
    {
        $urls = array_values(array_unique(array_filter($urls, static fn(string $u): bool => $u !== '')));

        if ($urls === []) {
            return [];
        }

        $problems = $this->problems();

        if ($problems !== []) {
            return $this->sameForAll($urls, SubmissionResult::failed(implode(' ', $problems)));
        }

        // The protocol is per-host: a URL list may only contain URLs of the `host` it declares, and
        // mixing them earns a 422 for the whole batch. Multi-site installs hit this immediately.
        $byHost = [];

        foreach ($urls as $url) {
            $host = parse_url($url, PHP_URL_HOST);

            if (!is_string($host) || $host === '') {
                $byHost['']['' . $url] = $url;

                continue;
            }

            $byHost[$host][$url] = $url;
        }

        $results = [];

        foreach ($byHost as $host => $group) {
            $group = array_values($group);

            if ($host === '') {
                $results += $this->sameForAll($group, SubmissionResult::failed(
                    Craft::t('sanka', 'IndexNow only accepts absolute URLs with a host.'),
                ));

                continue;
            }

            foreach (array_chunk($group, self::MAX_URLS) as $chunk) {
                $results += $this->post((string)$host, $chunk);
            }
        }

        return $results;
    }

    // ----------------------------------------------------------------- private

    /**
     * @param list<string> $urls
     * @return array<string, SubmissionResult>
     */
    private function post(string $host, array $urls): array
    {
        $payload = [
            'host' => $host,
            'key' => trim($this->settings->indexNowKey),
            'keyLocation' => $this->keyLocationFor($urls[0]),
            'urlList' => array_values($urls),
        ];

        $response = $this->http->request(
            'POST',
            'https://' . $this->settings->indexNowEndpoint . '/indexnow',
            ['Content-Type' => 'application/json; charset=utf-8'],
            (string)json_encode($payload, JSON_UNESCAPED_SLASHES),
        );

        return $this->sameForAll($urls, $this->interpret($response->statusCode, $response->body));
    }

    /**
     * Where the key file lives for the site a URL belongs to.
     *
     * Matched by base URL rather than by host so that two sites sharing a hostname under different
     * paths each declare their own key location — which is the case the scoping rule exists for.
     */
    private function keyLocationFor(string $url): string
    {
        foreach (Craft::$app->getSites()->getAllSites() as $site) {
            $base = rtrim((string)$site->getBaseUrl(), '/');

            if ($base !== '' && str_starts_with($url, $base)) {
                return $this->keyUrl($site->id);
            }
        }

        return $this->keyUrl();
    }

    private function interpret(int $status, string $body): SubmissionResult
    {
        $detail = trim(strip_tags($body));
        $detail = $detail !== '' ? ' (' . mb_substr($detail, 0, 200) . ')' : '';

        return match ($status) {
            200 => SubmissionResult::sent(200, Craft::t('sanka', 'Accepted.')),

            // Accepted, but the key file has not been fetched and confirmed yet. Not a failure and
            // not something to retry — the engine will come and read the key file on its own.
            202 => SubmissionResult::sent(202, Craft::t('sanka', 'Accepted. The engine has not verified the key file yet; it will fetch {url} shortly.', [
                'url' => $this->keyUrl(),
            ])),

            400 => SubmissionResult::failed(Craft::t('sanka', 'The engine rejected the request as malformed.') . $detail, 400),

            403 => SubmissionResult::failed(Craft::t('sanka', 'The key was rejected. The engine could not read {url}, or it did not contain the key. Check that the site is publicly reachable and that nothing is redirecting or password-protecting it.', [
                'url' => $this->keyUrl(),
            ]) . $detail, 403),

            422 => SubmissionResult::failed(Craft::t('sanka', 'The engine says these URLs are not on the host the key covers. Check for a mismatch between the site’s configured base URL and the domain it is actually served on.') . $detail, 422),

            429 => SubmissionResult::retry(Craft::t('sanka', 'Rate limited. Sanka will try again with a longer wait.') . $detail, 429),

            default => $status >= 500 || $status === 0
                ? SubmissionResult::retry(Craft::t('sanka', 'The engine could not be reached (HTTP {status}).', ['status' => $status]) . $detail, $status)
                : SubmissionResult::failed(Craft::t('sanka', 'The engine returned HTTP {status}.', ['status' => $status]) . $detail, $status),
        };
    }
}
