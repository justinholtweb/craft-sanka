<?php

declare(strict_types=1);

namespace justinholtweb\sanka\services;

use Craft;
use craft\base\Component;
use craft\elements\Entry;
use craft\helpers\StringHelper;
use craft\helpers\UrlHelper;
use craft\models\Section;
use justinholtweb\sanka\Plugin;

/**
 * `llms.txt`, and its longer sibling.
 *
 * A sitemap tells a crawler what exists. `llms.txt` tells a language model what the site is *about*
 * and which pages are worth reading — a curated map in Markdown, at a fixed path, that a model can
 * consume in one fetch instead of crawling a thousand URLs to find the six that matter.
 *
 * Two files, and the difference matters:
 *
 * - **`/llms.txt`** is the map: title, a one-line summary, then sections of linked pages with short
 *   descriptions. Small enough to fit comfortably in a context window.
 * - **`/llms-full.txt`** is the map with the text inlined. Much larger, and worth serving only for
 *   sites whose whole value is the writing — documentation, reference, a handbook.
 *
 * Both are generated from Craft's own content and cached, so neither can drift from what the site
 * actually says.
 */
class Llms extends Component
{
    /** Entries listed per section. Beyond this the file stops being a map and starts being a dump. */
    public const MAX_PER_SECTION = 200;

    /** Characters of body text per entry in the full file. */
    public const MAX_BODY = 20000;

    /**
     * Field handles checked, in order, for a one-line description of an entry.
     *
     * Convention over configuration: these are what Craft sites actually call the field, and a site
     * using none of them falls back to the opening of its own body text, which is nearly always
     * what a hand-written description would have said anyway.
     */
    private const DESCRIPTION_HANDLES = [
        'summary', 'description', 'metaDescription', 'seoDescription',
        'excerpt', 'standfirst', 'subtitle', 'intro', 'teaser', 'blurb',
    ];

    /**
     * The map.
     */
    public function map(?int $siteId = null): string
    {
        return $this->cached('map', $siteId, fn(): string => $this->build($siteId, false));
    }

    /**
     * The map with body text inlined.
     */
    public function full(?int $siteId = null): string
    {
        return $this->cached('full', $siteId, fn(): string => $this->build($siteId, true));
    }

    /**
     * Forget the cached files. Called whenever an entry is saved, since a stale map is worse than a
     * slow one — it is the file that tells a model which pages exist.
     */
    public function invalidate(): void
    {
        Craft::$app->getCache()->delete(self::class);

        foreach (Craft::$app->getSites()->getAllSites() as $site) {
            foreach (['map', 'full'] as $kind) {
                Craft::$app->getCache()->delete($this->cacheKey($kind, $site->id));
            }
        }
    }

    /**
     * The sections that will appear, in order.
     *
     * @return list<Section>
     */
    public function sections(?int $siteId = null): array
    {
        $settings = Plugin::getInstance()->getSettings();
        $siteId ??= Craft::$app->getSites()->getCurrentSite()->id;
        $all = Craft::$app->getEntries()->getAllSections();

        $usable = array_values(array_filter($all, static function(Section $section) use ($siteId): bool {
            if ($section->type === Section::TYPE_SINGLE) {
                // Singles belong in the map — a home page or an about page is exactly the sort of
                // thing a model should be pointed at — but they are listed individually, not as a
                // section of one, so they are gathered separately.
                return false;
            }

            foreach ($section->getSiteSettings() as $siteSettings) {
                if ($siteSettings->siteId === $siteId && $siteSettings->hasUrls) {
                    return true;
                }
            }

            return false;
        }));

        if ($settings->llmsSections === []) {
            return $usable;
        }

        $wanted = array_flip($settings->llmsSections);
        $chosen = array_values(array_filter($usable, static fn(Section $s): bool => isset($wanted[$s->handle])));

        // Keep the operator's order, not Craft's.
        usort($chosen, static fn(Section $a, Section $b): int => ($wanted[$a->handle] ?? 0) <=> ($wanted[$b->handle] ?? 0));

        return $chosen;
    }

    // ----------------------------------------------------------------- private

    private function build(?int $siteId, bool $full): string
    {
        $sites = Craft::$app->getSites();
        $site = $siteId !== null ? $sites->getSiteById($siteId) : $sites->getCurrentSite();
        $site ??= $sites->getPrimarySite();

        $settings = Plugin::getInstance()->getSettings();
        $out = ['# ' . $site->getName()];

        $summary = trim($settings->llmsSummary);

        if ($summary !== '') {
            $out[] = '';
            $out[] = '> ' . str_replace("\n", ' ', $summary);
        }

        $out[] = '';
        $out[] = '<!-- ' . Craft::t('sanka', 'Generated by Sanka from this site’s own content.') . ' -->';

        $singles = $this->singles($site->id);

        if ($singles !== []) {
            $out[] = '';
            $out[] = '## ' . Craft::t('sanka', 'Key pages');
            $out[] = '';

            foreach ($singles as $entry) {
                $out[] = $this->line($entry, $full);
            }
        }

        foreach ($this->sections($site->id) as $section) {
            $entries = Entry::find()
                ->section($section->handle)
                ->siteId($site->id)
                ->status(Entry::STATUS_LIVE)
                ->orderBy(['postDate' => SORT_DESC, 'title' => SORT_ASC])
                ->limit(self::MAX_PER_SECTION)
                ->all();

            if ($entries === []) {
                continue;
            }

            $out[] = '';
            $out[] = '## ' . $section->name;
            $out[] = '';

            foreach ($entries as $entry) {
                $out[] = $this->line($entry, $full);
            }
        }

        return implode("\n", $out) . "\n";
    }

    /**
     * @return list<Entry>
     */
    private function singles(int $siteId): array
    {
        /** @var list<Entry> */
        return Entry::find()
            ->section(array_map(
                static fn(Section $s): string => $s->handle,
                array_filter(
                    Craft::$app->getEntries()->getAllSections(),
                    static fn(Section $s): bool => $s->type === Section::TYPE_SINGLE,
                ),
            ) ?: ['__none__'])
            ->siteId($siteId)
            ->status(Entry::STATUS_LIVE)
            ->all();
    }

    private function line(Entry $entry, bool $full): string
    {
        $url = (string)$entry->getUrl();

        if ($url === '') {
            return '';
        }

        $line = '- [' . $this->escape($entry->title ?? $url) . '](' . $url . ')';
        $description = $this->describe($entry);

        if ($description !== '') {
            $line .= ': ' . $description;
        }

        if (!$full) {
            return $line;
        }

        $body = $this->body($entry);

        return $body === '' ? $line : $line . "\n\n" . $body . "\n";
    }

    private function describe(Entry $entry): string
    {
        foreach (self::DESCRIPTION_HANDLES as $handle) {
            $value = $this->fieldText($entry, $handle);

            if ($value !== '') {
                return $this->oneLine($value, 300);
            }
        }

        $body = $this->body($entry);

        return $body === '' ? '' : $this->oneLine($body, 200);
    }

    /**
     * Every text-bearing field on an entry, flattened.
     *
     * Field layouts are walked rather than a fixed handle being read, because there is no such thing
     * as “the body field” in Craft and guessing one would produce an empty file on most sites.
     */
    private function body(Entry $entry): string
    {
        $parts = [];

        foreach ($entry->getFieldLayout()?->getCustomFields() ?? [] as $field) {
            $text = $this->fieldText($entry, $field->handle);

            if ($text !== '') {
                $parts[] = $text;
            }
        }

        $body = trim(implode("\n\n", $parts));

        return mb_substr($body, 0, self::MAX_BODY);
    }

    private function fieldText(Entry $entry, string $handle): string
    {
        try {
            $value = $entry->getFieldValue($handle);
        } catch (\Throwable) {
            return '';
        }

        // Only scalars and rich text are read. Anything else — a relation, a Matrix field, an asset
        // — is a container, and every Craft element is `Traversable`, so “flatten anything iterable”
        // would silently explode elements into their attribute values.
        if (is_string($value)) {
            return $this->plain($value);
        }

        if (is_object($value) && method_exists($value, '__toString')) {
            return $this->plain((string)$value);
        }

        return '';
    }

    private function plain(string $html): string
    {
        $text = strip_tags(str_replace(['</p>', '<br>', '<br />', '</li>', '</h2>', '</h3>'], "\n", $html));
        $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');

        return trim((string)preg_replace("/\n{3,}/", "\n\n", (string)preg_replace('/[ \t]+/', ' ', $text)));
    }

    private function oneLine(string $text, int $length): string
    {
        return $this->escape(StringHelper::safeTruncate(trim((string)preg_replace('/\s+/', ' ', $text)), $length, '…'));
    }

    private function escape(string $text): string
    {
        // Only the two characters that would break a Markdown link. Escaping more would show up as
        // backslashes in a file whose whole purpose is being read as prose.
        return str_replace(['[', ']'], ['\[', '\]'], $text);
    }

    /**
     * @param callable(): string $builder
     */
    private function cached(string $kind, ?int $siteId, callable $builder): string
    {
        $duration = Plugin::getInstance()->getSettings()->llmsCacheDuration;

        if ($duration <= 0) {
            return $builder();
        }

        $key = $this->cacheKey($kind, $siteId ?? Craft::$app->getSites()->getCurrentSite()->id);
        $cache = Craft::$app->getCache();
        $cached = $cache->get($key);

        if (is_string($cached)) {
            return $cached;
        }

        $value = $builder();
        $cache->set($key, $value, $duration);

        return $value;
    }

    private function cacheKey(string $kind, int $siteId): string
    {
        return self::class . ":{$kind}:{$siteId}";
    }
}
