<?php

declare(strict_types=1);

namespace justinholtweb\sanka\engines;

use Craft;
use craft\base\PluginInterface;
use craft\helpers\UrlHelper;
use justinholtweb\sanka\http\HttpClientInterface;
use justinholtweb\sanka\models\Settings;
use justinholtweb\sanka\models\SubmissionResult;
use Throwable;

/**
 * Sitemap resubmission.
 *
 * Worth being blunt about what this is and is not. **Google retired its sitemap ping endpoint in
 * 2023** — `google.com/ping?sitemap=` does nothing at all now, and any tool still offering it is
 * selling a no-op. Google discovers sitemap changes through `robots.txt` and Search Console, and
 * there is no API to hurry it.
 *
 * What does still work is IndexNow: a sitemap is a URL, and submitting it tells the participating
 * engines to come and re-read it. That is one call that can move thousands of pages, which makes it
 * the right tool for a bulk change — a migration, a re-slug, a section going live — where
 * submitting every affected URL individually would exhaust a day's quota by lunchtime.
 *
 * So this is a separate engine rather than a checkbox on IndexNow: different unit of work,
 * different quota accounting, different reason for existing. It shares IndexNow's transport
 * because sharing it is the honest implementation.
 */
class SitemapEngine extends BaseEngine
{
    public const HANDLE = 'sitemap';

    /**
     * SEO plugins that can be asked where their sitemap index really is.
     *
     * Keyed by plugin handle, so a site without the plugin never loads its classes. The resolver is
     * called once per site and may return null or throw; either means “ask something else”.
     *
     * @var array<string, array{class-string, string}> static callables, `(PluginInterface, int): ?string`
     */
    private const SITEMAP_PLUGINS = [
        'seomatic' => [self::class, 'seomaticSitemapUrl'],
    ];

    public function __construct(
        Settings $settings,
        HttpClientInterface $http,
        private readonly IndexNowEngine $indexNow,
    ) {
        parent::__construct($settings, $http);
    }

    public function handle(): string
    {
        return self::HANDLE;
    }

    public function label(): string
    {
        return Craft::t('sanka', 'Sitemap resubmission');
    }

    public function description(): string
    {
        return Craft::t('sanka', 'Asks the IndexNow engines to re-read a sitemap — one call that can move thousands of pages, which is what you want after a migration or a bulk change. Google retired its sitemap ping in 2023 and has no equivalent.');
    }

    public function isEnabled(): bool
    {
        return $this->settings->sitemapEnabled;
    }

    public function problems(): array
    {
        $problems = [];

        // It travels over IndexNow, so IndexNow has to be working first. Saying that plainly beats
        // letting somebody enable this and watch nothing happen.
        if (!$this->settings->indexNowEnabled) {
            $problems[] = Craft::t('sanka', 'Sitemap resubmission travels over IndexNow, which is switched off.');
        }

        foreach ($this->indexNow->problems() as $problem) {
            $problems[] = $problem;
        }

        if ($this->sitemapUrls() === []) {
            $problems[] = Craft::t('sanka', 'No sitemaps found. Add their URLs, or install a plugin that generates one.');
        }

        return $problems;
    }

    public function maxPerCall(): int
    {
        return IndexNowEngine::MAX_URLS;
    }

    public function dailyQuota(): ?int
    {
        return null;
    }

    public function submit(array $urls, string $type): array
    {
        if ($urls === []) {
            return [];
        }

        // A sitemap cannot be “deleted” in any sense an engine acts on, and quietly turning the
        // request into an update would be worse than declining it.
        if ($type !== \justinholtweb\sanka\records\SubmissionRecord::TYPE_UPDATED) {
            return $this->sameForAll($urls, SubmissionResult::skipped(
                Craft::t('sanka', 'Sitemaps are only ever resubmitted, never withdrawn.'),
            ));
        }

        return $this->indexNow->submit($urls, $type);
    }

    /**
     * SEOmatic's sitemap index for one site.
     *
     * Reached through the service rather than the `seomatic.helper` Twig variable so that no
     * template needs rendering, and guarded by `method_exists` because this is another plugin's
     * internals and it is allowed to move them.
     */
    /** @phpstan-ignore method.unused (called through SITEMAP_PLUGINS) */
    private static function seomaticSitemapUrl(PluginInterface $plugin, int $siteId): ?string
    {
        $sitemaps = $plugin->sitemaps ?? null;

        if ($sitemaps === null || !method_exists($sitemaps, 'sitemapIndexUrlForSiteId')) {
            return null;
        }

        return $sitemaps->sitemapIndexUrlForSiteId($siteId) ?: null;
    }

    /**
     * The sitemaps Sanka will resubmit.
     *
     * Three sources, in order: what the operator configured, what an installed SEO plugin says its
     * sitemap index actually is, and finally each site's `/sitemap.xml`. None of them is checked
     * over the network here — this runs on the settings screen, and a probe per site on every
     * render is not acceptable — so existence is confirmed only when the operator asks.
     *
     * Asking the SEO plugin matters because the guess is wrong on the most common setup there is.
     * SEOmatic's index lives at `/sitemaps-<groupId>-sitemap.xml` and only *redirects* from
     * `/sitemap.xml`; submitting the redirect asks every IndexNow engine to follow a hop it did not
     * need to, and records a URL in the ledger that is not the one being read.
     *
     * @return list<string>
     */
    public function sitemapUrls(): array
    {
        $configured = [];

        foreach ($this->settings->sitemapUrls as $url) {
            if (is_string($url) && trim($url) !== '') {
                $configured[] = trim($url);
            }
        }

        if ($configured !== []) {
            return array_values(array_unique($configured));
        }

        $detected = $this->detectedUrls();

        return $detected !== [] ? $detected : $this->guessedUrls();
    }

    /**
     * Where {@see self::sitemapUrls()} got its answer, for the screens that show the list.
     *
     * “Configured”, the name of the plugin that was asked, or null for the guess. A list nobody can
     * account for is the one that gets quietly ignored when it turns out to be wrong.
     */
    public function sitemapSource(): ?string
    {
        foreach ($this->settings->sitemapUrls as $url) {
            if (is_string($url) && trim($url) !== '') {
                return Craft::t('sanka', 'Configured here');
            }
        }

        foreach (self::SITEMAP_PLUGINS as $handle => $resolver) {
            if ($this->pluginUrls($handle, $resolver) !== []) {
                return Craft::$app->getPlugins()->getPlugin($handle)->name ?? $handle;
            }
        }

        return null;
    }

    /**
     * The sitemap index an installed SEO plugin publishes, if one is installed and can say.
     *
     * @return list<string>
     */
    private function detectedUrls(): array
    {
        foreach (self::SITEMAP_PLUGINS as $handle => $resolver) {
            $urls = $this->pluginUrls($handle, $resolver);

            if ($urls !== []) {
                return $urls;
            }
        }

        return [];
    }

    /**
     * @param callable(PluginInterface, int): ?string $resolver
     * @return list<string>
     */
    private function pluginUrls(string $handle, callable $resolver): array
    {
        $plugin = Craft::$app->getPlugins()->getPlugin($handle);

        if ($plugin === null) {
            return [];
        }

        $urls = [];

        foreach (Craft::$app->getSites()->getAllSites() as $site) {
            if (trim((string)$site->getBaseUrl()) === '') {
                continue;
            }

            try {
                // Another plugin's internals are not a contract. A version that moved the method,
                // a licence that lapsed and disabled half of it, a sitemap feature switched off —
                // all of those are “this plugin cannot tell us”, not a reason to take the request
                // down, so the guess is used instead.
                $url = $resolver($plugin, $site->id);
            } catch (Throwable $e) {
                Craft::warning("Could not read sitemap URLs from {$handle}: {$e->getMessage()}", __METHOD__);

                return [];
            }

            if (is_string($url) && trim($url) !== '') {
                $urls[] = trim($url);
            }
        }

        return array_values(array_unique($urls));
    }

    /**
     * @return list<string>
     */
    private function guessedUrls(): array
    {
        $guessed = [];

        foreach (Craft::$app->getSites()->getAllSites() as $site) {
            if (trim((string)$site->getBaseUrl()) === '') {
                continue;
            }

            $guessed[] = UrlHelper::siteUrl('sitemap.xml', null, null, $site->id);
        }

        return array_values(array_unique($guessed));
    }
}
