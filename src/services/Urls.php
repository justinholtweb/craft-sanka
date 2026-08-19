<?php

declare(strict_types=1);

namespace justinholtweb\sanka\services;

use Craft;
use craft\base\Component;
use craft\base\ElementInterface;
use craft\elements\Entry;
use justinholtweb\sanka\Plugin;

/**
 * The one place that decides whether a URL can be submitted at all.
 *
 * Every engine punishes a bad URL differently — Google answers 403, IndexNow answers 422, and both
 * of them charge for the attempt. So the judgement is made here, once, before anything is queued,
 * and the reason is recorded on the ledger row as a `skipped` outcome rather than discovered as a
 * failure a day later.
 *
 * The checks are deliberately **syntactic**. Sanka does not fetch the URL to see whether it is
 * really reachable: that would be a request per submission against the operator's own site, and the
 * engines are about to make that request anyway.
 */
class Urls extends Component
{
    /**
     * Hosts that are never worth submitting. Development domains reach the engines as a 403 or a
     * timeout, and a developer testing on `.ddev.site` should be told that plainly rather than
     * watching every submission fail.
     */
    private const LOCAL_SUFFIXES = ['.local', '.localhost', '.test', '.ddev.site', '.internal', '.invalid', '.example'];

    private const LOCAL_HOSTS = ['localhost', '127.0.0.1', '::1'];

    /**
     * The absolute URL for an element, or null if it has none.
     */
    public function forElement(ElementInterface $element): ?string
    {
        $url = $element->getUrl();

        return is_string($url) && $url !== '' ? $url : null;
    }

    /**
     * Why this URL cannot be submitted, or null if it can.
     *
     * Phrased as the operator's next action, not as a rule name.
     */
    public function problem(string $url): ?string
    {
        $url = trim($url);

        if ($url === '') {
            return Craft::t('sanka', 'Empty URL.');
        }

        $parts = parse_url($url);

        if ($parts === false || !isset($parts['scheme'], $parts['host'])) {
            return Craft::t('sanka', 'Not an absolute URL. Submissions need the scheme and host — “https://example.com/page”, not “/page”.');
        }

        $scheme = strtolower((string)$parts['scheme']);

        if (!in_array($scheme, ['http', 'https'], true)) {
            return Craft::t('sanka', 'Only http and https URLs can be submitted.');
        }

        $host = strtolower((string)$parts['host']);

        if ($this->isLocalHost($host) && !Plugin::getInstance()->getSettings()->allowLocalHosts) {
            return Craft::t('sanka', 'That host is not reachable from the internet, so no engine could fetch it. Turn on “allow local hosts” in settings if you are testing.');
        }

        if (isset($parts['fragment'])) {
            return Craft::t('sanka', 'Fragments are not part of a URL as far as an engine is concerned. Submit the URL without the “#” part.');
        }

        // Craft's preview and share tokens make a URL that shows content nobody else can see. One
        // getting into the ledger would be a private draft handed to a search engine.
        if (isset($parts['query']) && preg_match('/(^|&)token=/', (string)$parts['query'])) {
            return Craft::t('sanka', 'That looks like a preview URL. Submitting it would hand a private draft to a search engine.');
        }

        if (!$this->isKnownHost($host)) {
            return Craft::t('sanka', 'No site in this Craft install is served from {host}, so the engines would reject it as a URL you do not own.', ['host' => $host]);
        }

        return null;
    }

    public function isSubmittable(string $url): bool
    {
        return $this->problem($url) === null;
    }

    /**
     * Which site a URL belongs to, matched by longest base URL so that `/en` and `/en-gb` do not
     * shadow each other.
     */
    public function siteIdForUrl(string $url): ?int
    {
        $best = null;
        $bestLength = -1;

        foreach (Craft::$app->getSites()->getAllSites() as $site) {
            $base = rtrim((string)$site->getBaseUrl(), '/');

            if ($base === '' || !str_starts_with($url, $base)) {
                continue;
            }

            if (strlen($base) > $bestLength) {
                $best = $site->id;
                $bestLength = strlen($base);
            }
        }

        return $best;
    }

    /**
     * The indexed form of a URL.
     *
     * URLs are longer than MySQL will index and repeat constantly, and every lookup that matters —
     * cooldown, deduplication, “show me this URL's history” — is an equality test.
     */
    public function hash(string $url): string
    {
        return sha1(trim($url));
    }

    /**
     * Whether an entry is in a state worth telling an engine about.
     *
     * A disabled or expired entry has no business being submitted as an update; it is a `delete`
     * if it ever had a URL, and nothing at all if it did not.
     */
    public function isLiveEntry(Entry $entry): bool
    {
        return $entry->getStatus() === Entry::STATUS_LIVE && !$entry->getIsDraft() && !$entry->getIsRevision();
    }

    /**
     * Every host this Craft install answers on.
     *
     * @return list<string>
     */
    public function knownHosts(): array
    {
        $hosts = [];

        foreach (Craft::$app->getSites()->getAllSites() as $site) {
            $host = parse_url((string)$site->getBaseUrl(), PHP_URL_HOST);

            if (is_string($host) && $host !== '') {
                $hosts[] = strtolower($host);
            }
        }

        return array_values(array_unique($hosts));
    }

    // ----------------------------------------------------------------- private

    private function isKnownHost(string $host): bool
    {
        $known = $this->knownHosts();

        // An install whose sites have no base URL at all — a headless setup driven entirely by
        // aliases — would otherwise be unable to submit anything. Better to let it through and let
        // the engine be the judge than to block it here on no evidence.
        if ($known === []) {
            return true;
        }

        return in_array($host, $known, true);
    }

    private function isLocalHost(string $host): bool
    {
        if (in_array($host, self::LOCAL_HOSTS, true)) {
            return true;
        }

        foreach (self::LOCAL_SUFFIXES as $suffix) {
            if (str_ends_with($host, $suffix)) {
                return true;
            }
        }

        if (filter_var($host, FILTER_VALIDATE_IP) !== false) {
            return filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false;
        }

        // A bare hostname with no dot is a LAN name, not a domain.
        return !str_contains($host, '.');
    }
}
