<?php

declare(strict_types=1);

namespace justinholtweb\sanka\engines;

use Craft;
use craft\helpers\UrlHelper;
use justinholtweb\sanka\http\HttpClientInterface;
use justinholtweb\sanka\models\Settings;
use justinholtweb\sanka\models\SubmissionResult;

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
     * The sitemaps Sanka will resubmit.
     *
     * Configured URLs win. Failing that, each site's `/sitemap.xml` is offered — the convention
     * every Craft SEO plugin follows — and it is checked for existence only when the operator asks,
     * not here, because this method is called on the settings screen and a network probe per site
     * on every render is not acceptable.
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
