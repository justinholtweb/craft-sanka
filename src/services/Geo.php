<?php

declare(strict_types=1);

namespace justinholtweb\sanka\services;

use Craft;
use craft\base\Component;
use craft\base\ElementInterface;
use DOMDocument;
use DOMElement;
use DOMXPath;
use justinholtweb\sanka\errors\SankaException;
use justinholtweb\sanka\models\GeoFinding as F;
use justinholtweb\sanka\models\GeoReport;
use justinholtweb\sanka\Plugin;

/**
 * Whether a page is written in a shape an answer engine can quote.
 *
 * Every check here is deterministic and reads only the page's own HTML. Nothing is sent anywhere,
 * nothing is scored by a model, and running the audit twice on the same page gives the same answer —
 * which is the only way a score is worth acting on.
 *
 * The HTML comes from one of exactly two places: a **same-origin** fetch of a URL derived from
 * Craft's own site configuration, or HTML pasted into the control panel. A URL typed by a user is
 * never fetched, so there is no SSRF surface here to fence off.
 */
class Geo extends Component
{
    /** `@type` values that tell a parser what a page actually is. */
    private const USEFUL_TYPES = [
        'Article', 'BlogPosting', 'NewsArticle', 'TechArticle', 'Report', 'ScholarlyArticle',
        'FAQPage', 'QAPage', 'HowTo', 'Recipe', 'Product', 'Service', 'Event', 'Course',
        'LocalBusiness', 'Organization', 'Person', 'SoftwareApplication', 'WebPage', 'AboutPage',
    ];

    /**
     * Audit an element by fetching its own URL.
     *
     * @throws SankaException if the element has no URL, or the fetch fails
     */
    public function auditElement(ElementInterface $element): GeoReport
    {
        $url = Plugin::getInstance()->urls->forElement($element);

        if ($url === null) {
            throw new SankaException(Craft::t('sanka', 'That entry has no URL, so there is no page to audit.'));
        }

        return $this->auditUrl($url);
    }

    /**
     * Audit a URL this Craft install serves.
     *
     * @throws SankaException if the URL is not this site's, or cannot be read
     */
    public function auditUrl(string $url): GeoReport
    {
        $plugin = Plugin::getInstance();

        // The one gate that matters: Sanka fetches its own site and nothing else. This is checked
        // here rather than trusted from the caller, because every caller is a controller action.
        $host = parse_url($url, PHP_URL_HOST);

        if (!is_string($host) || !in_array(strtolower($host), $plugin->urls->knownHosts(), true)) {
            throw new SankaException(Craft::t('sanka', 'Sanka only audits pages on this install’s own sites.'));
        }

        $response = $plugin->engines->getHttpClient()->request('GET', $url, [
            'Accept' => 'text/html',
            'User-Agent' => 'Sanka/1.0 (+Craft CMS readiness audit)',
        ]);

        if (!$response->isSuccessful()) {
            throw new SankaException(Craft::t('sanka', 'The page answered HTTP {status}, so there was nothing to audit.', [
                'status' => $response->statusCode,
            ]));
        }

        return $this->audit($response->body, $url);
    }

    /**
     * Audit HTML directly.
     */
    public function audit(string $html, string $url = ''): GeoReport
    {
        $doc = $this->parse($html);
        $xpath = new DOMXPath($doc);
        $jsonLd = $this->jsonLd($xpath);
        $text = $this->mainText($xpath);

        $findings = array_merge(
            $this->structure($xpath, $text),
            $this->attribution($xpath, $jsonLd),
            $this->machine($xpath, $jsonLd),
            $this->citability($xpath, $text),
        );

        return new GeoReport($url, $findings, strlen($html));
    }

    // ------------------------------------------------------------- structure

    /**
     * @return list<F>
     */
    private function structure(DOMXPath $xpath, string $text): array
    {
        $findings = [];
        $d = F::DIMENSION_STRUCTURE;

        $h1s = $xpath->query('//h1');
        $count = $h1s === false ? 0 : $h1s->length;

        $findings[] = match (true) {
            $count === 1 => F::pass('h1-single', $d, 'Single H1', 'Exactly one H1, so the page states what it is about once.', 2),
            $count === 0 => F::fail('h1-single', $d, 'Single H1', 'No H1 at all.', 'Add one H1 that names the subject of the page in the words someone would ask about it.', 2),
            default => F::warn('h1-single', $d, 'Single H1', "{$count} H1s, so the page claims to be about several things.", 'Keep one H1 and demote the rest to H2.', 2),
        };

        $findings[] = $this->outline($xpath, $d);

        $words = str_word_count($text);

        $findings[] = match (true) {
            $words >= 300 => F::pass('word-count', $d, 'Enough to quote', "About {$words} words.", 1),
            $words >= 120 => F::warn('word-count', $d, 'Enough to quote', "About {$words} words, which is thin.", 'Answer engines cite passages, not pages. Under a few hundred words there is rarely a passage worth lifting.', 1),
            default => F::fail('word-count', $d, 'Enough to quote', "About {$words} words.", 'There is not enough here for an engine to quote. Either expand it or accept that this page is navigation rather than an answer.', 1),
        };

        $findings[] = $this->answerFirst($xpath, $d);
        $findings[] = $this->paragraphs($xpath, $d);
        $findings[] = $this->quotableShapes($xpath, $d);

        return $findings;
    }

    private function outline(DOMXPath $xpath, string $d): F
    {
        $nodes = $xpath->query('//h1|//h2|//h3|//h4|//h5|//h6');
        $levels = [];

        if ($nodes !== false) {
            foreach ($nodes as $node) {
                if ($node instanceof DOMElement) {
                    $levels[] = (int)substr($node->tagName, 1);
                }
            }
        }

        if (count($levels) < 3) {
            return F::warn('heading-outline', $d, 'Scannable outline', 'Fewer than three headings, so the page has no structure to navigate.', 'Break the page into sections with H2s that read as the questions each section answers.', 2);
        }

        $skips = 0;

        for ($i = 1, $n = count($levels); $i < $n; $i++) {
            if ($levels[$i] - $levels[$i - 1] > 1) {
                $skips++;
            }
        }

        return $skips === 0
            ? F::pass('heading-outline', $d, 'Scannable outline', count($levels) . ' headings, no skipped levels.', 2)
            : F::warn('heading-outline', $d, 'Scannable outline', "{$skips} skipped heading levels break the outline.", 'Go from H2 to H3 without jumping. A parser builds the page’s structure from these levels and a skip merges two sections into one.', 2);
    }

    private function answerFirst(DOMXPath $xpath, string $d): F
    {
        $nodes = $xpath->query('(//h1)[1]/following::p[normalize-space(.)!=""][1]');
        $first = $nodes !== false && $nodes->length > 0 ? trim((string)$nodes->item(0)?->textContent) : '';

        if ($first === '') {
            $nodes = $xpath->query('//p[normalize-space(.)!=""][1]');
            $first = $nodes !== false && $nodes->length > 0 ? trim((string)$nodes->item(0)?->textContent) : '';
        }

        $length = mb_strlen($first);

        return match (true) {
            $length === 0 => F::fail('answer-first', $d, 'Answers first', 'No opening paragraph found after the heading.', 'Open with a paragraph that answers the question the heading asks. It is the passage most likely to be lifted verbatim.', 2),
            $length < 40 => F::warn('answer-first', $d, 'Answers first', 'The opening paragraph is too short to stand on its own as a quote.', 'Make the first paragraph a self-contained answer of roughly two or three sentences.', 2),
            $length <= 600 => F::pass('answer-first', $d, 'Answers first', 'The page opens with a self-contained paragraph.', 2),
            default => F::warn('answer-first', $d, 'Answers first', 'The opening paragraph runs to ' . $length . ' characters before it gets anywhere.', 'Lead with the answer and move the build-up below it. An engine quoting the top of the page should get the point, not the preamble.', 2),
        };
    }

    private function paragraphs(DOMXPath $xpath, string $d): F
    {
        $lengths = [];
        $nodes = $xpath->query('//p');

        if ($nodes !== false) {
            foreach ($nodes as $node) {
                $words = str_word_count(trim((string)$node->textContent));

                if ($words > 0) {
                    $lengths[] = $words;
                }
            }
        }

        if ($lengths === []) {
            return F::fail('paragraph-length', $d, 'Passage-sized paragraphs', 'No paragraphs at all.', 'Wrap prose in paragraphs. A wall of text inside one element has no passage boundaries for an engine to cut on — and a page with none has nothing to quote.', 1);
        }

        sort($lengths);
        $median = $lengths[intdiv(count($lengths), 2)];

        return $median <= 90
            ? F::pass('paragraph-length', $d, 'Passage-sized paragraphs', "Median paragraph is {$median} words.", 1)
            : F::warn('paragraph-length', $d, 'Passage-sized paragraphs', "Median paragraph is {$median} words, which is long to quote.", 'Split long paragraphs. Each one should carry a single claim that survives being lifted out on its own.', 1);
    }

    private function quotableShapes(DOMXPath $xpath, string $d): F
    {
        $lists = $this->count($xpath, '//ul|//ol');
        $tables = $this->count($xpath, '//table');

        return $lists + $tables > 0
            ? F::pass('quotable-shapes', $d, 'Structured passages', "{$lists} lists and {$tables} tables give an engine something to lift whole.", 1)
            : F::warn('quotable-shapes', $d, 'Structured passages', 'No lists or tables.', 'Steps, comparisons and criteria get quoted far more readily as a list or a table than as prose.', 1);
    }

    // ----------------------------------------------------------- attribution

    /**
     * @param list<array<string, mixed>> $jsonLd
     * @return list<F>
     */
    private function attribution(DOMXPath $xpath, array $jsonLd): array
    {
        $d = F::DIMENSION_ATTRIBUTION;
        $findings = [];

        $author = $this->pluck($jsonLd, 'author') !== null
            || $this->count($xpath, '//meta[@name="author"]') > 0
            || $this->count($xpath, '//*[@rel="author"]') > 0;

        $findings[] = $author
            ? F::pass('author', $d, 'Named author', 'The page says who wrote it.', 2)
            : F::fail('author', $d, 'Named author', 'No author anywhere in the markup.', 'Add an author — in the JSON-LD, or at minimum a `<meta name="author">`. Attribution is one of the few signals an engine can check cheaply, and unattributed pages lose to attributed ones on the same facts.', 2);

        $published = $this->pluck($jsonLd, 'datePublished') !== null || $this->count($xpath, '//time[@datetime]') > 0;

        $findings[] = $published
            ? F::pass('date-published', $d, 'Publication date', 'The page carries a machine-readable publication date.', 1)
            : F::warn('date-published', $d, 'Publication date', 'No machine-readable publication date.', 'Add `datePublished` to the JSON-LD, or a `<time datetime="…">`. A date a parser has to guess at is a date it discounts.', 1);

        $modified = $this->pluck($jsonLd, 'dateModified') !== null;

        $findings[] = $modified
            ? F::pass('date-modified', $d, 'Last updated', 'The page says when it was last changed.', 1)
            : F::warn('date-modified', $d, 'Last updated', 'No `dateModified`.', 'Publish `dateModified` and keep it accurate. Freshness is weighted heavily by answer engines and is the cheapest signal on this list to emit correctly.', 1);

        $publisher = $this->pluck($jsonLd, 'publisher') !== null || $this->hasType($jsonLd, ['Organization', 'LocalBusiness']);

        $findings[] = $publisher
            ? F::pass('publisher', $d, 'Publisher identified', 'An organisation is named in the structured data.', 1)
            : F::warn('publisher', $d, 'Publisher identified', 'No publishing organisation in the structured data.', 'Add a `publisher` with the organisation’s name and URL, so the site resolves to an entity rather than a hostname.', 1);

        return $findings;
    }

    // --------------------------------------------------------------- machine

    /**
     * @param list<array<string, mixed>> $jsonLd
     * @return list<F>
     */
    private function machine(DOMXPath $xpath, array $jsonLd): array
    {
        $d = F::DIMENSION_MACHINE;
        $findings = [];

        $findings[] = $jsonLd !== []
            ? F::pass('jsonld', $d, 'Structured data', count($jsonLd) . ' JSON-LD block(s) parsed cleanly.', 3)
            : F::fail('jsonld', $d, 'Structured data', 'No parseable JSON-LD on the page.', 'Add JSON-LD. It is the single highest-leverage change on this list: it turns a page from text an engine has to interpret into facts it can read.', 3);

        $types = $this->types($jsonLd);
        $useful = array_intersect($types, self::USEFUL_TYPES);

        $findings[] = match (true) {
            $useful !== [] => F::pass('jsonld-type', $d, 'Meaningful @type', 'Declares ' . implode(', ', $useful) . '.', 2),
            $types !== [] => F::warn('jsonld-type', $d, 'Meaningful @type', 'Structured data is present but only declares ' . implode(', ', $types) . '.', 'Add a type that says what the page is — Article, FAQPage, HowTo, Product. A BreadcrumbList on its own tells an engine where the page sits, not what it says.', 2),
            default => F::fail('jsonld-type', $d, 'Meaningful @type', 'No `@type` to read.', 'Declare what the page is with a schema.org type.', 2),
        };

        $faq = $this->hasType($jsonLd, ['FAQPage', 'QAPage', 'HowTo'])
            || $this->count($xpath, '//h2[contains(., "?")]|//h3[contains(., "?")]') >= 2;

        $findings[] = $faq
            ? F::pass('question-shapes', $d, 'Question and answer shapes', 'The page is organised around questions.', 2)
            : F::warn('question-shapes', $d, 'Question and answer shapes', 'Nothing on the page is shaped as a question and its answer.', 'Add a short FAQ with FAQPage markup, or phrase some H2s as the questions readers actually ask. This is the format answer engines quote most often.', 2);

        $canonical = $xpath->query('//link[@rel="canonical"]/@href');
        $canonicalHref = $canonical !== false && $canonical->length > 0 ? trim((string)$canonical->item(0)?->nodeValue) : '';

        $findings[] = match (true) {
            $canonicalHref === '' => F::warn('canonical', $d, 'Canonical URL', 'No canonical link.', 'Add `<link rel="canonical">` so duplicate paths, tracking parameters and paginated variants all resolve to one address.', 1),
            !preg_match('#^https?://#i', $canonicalHref) => F::warn('canonical', $d, 'Canonical URL', 'The canonical link is relative.', 'Make the canonical URL absolute. A relative one is resolved differently by different consumers, which defeats the point of having it.', 1),
            default => F::pass('canonical', $d, 'Canonical URL', 'Absolute canonical URL present.', 1),
        };

        $robots = $xpath->query('//meta[translate(@name,"ROBTS","robts")="robots"]/@content');
        $robotsValue = $robots !== false && $robots->length > 0 ? strtolower((string)$robots->item(0)?->nodeValue) : '';

        $findings[] = str_contains($robotsValue, 'noindex')
            ? F::fail('noindex', $d, 'Indexable', 'The page carries `noindex`.', 'Nothing else on this list matters while this is set. Remove it, or accept that the page is deliberately invisible.', 3)
            : F::pass('noindex', $d, 'Indexable', 'No `noindex` on the page.', 3);

        $findings[] = $this->titleAndDescription($xpath, $d);

        return $findings;
    }

    private function titleAndDescription(DOMXPath $xpath, string $d): F
    {
        $titles = $xpath->query('//title');
        $title = $titles !== false && $titles->length > 0 ? trim((string)$titles->item(0)?->textContent) : '';

        $descriptions = $xpath->query('//meta[translate(@name,"DESCRIPTON","descripton")="description"]/@content');
        $description = $descriptions !== false && $descriptions->length > 0 ? trim((string)$descriptions->item(0)?->nodeValue) : '';

        $problems = [];

        if ($title === '') {
            $problems[] = 'no `<title>`';
        } elseif (mb_strlen($title) > 70) {
            $problems[] = 'the title runs past 70 characters';
        }

        if ($description === '') {
            $problems[] = 'no meta description';
        } elseif (mb_strlen($description) < 50) {
            $problems[] = 'the meta description is under 50 characters';
        }

        if ($problems === []) {
            return F::pass('title-description', $d, 'Title and description', 'Both present and sensibly sized.', 1);
        }

        return F::warn('title-description', $d, 'Title and description', ucfirst(implode(', and ', $problems)) . '.', 'These are what an engine shows when it cites the page. They are also the cheapest thing on this list to fix.', 1);
    }

    // ------------------------------------------------------------ citability

    /**
     * @return list<F>
     */
    private function citability(DOMXPath $xpath, string $text): array
    {
        $d = F::DIMENSION_CITABILITY;
        $findings = [];

        preg_match_all('/\b\d[\d,.]*\s?(?:%|percent|per cent|million|billion|thousand|[A-Z]{2,4}\b)/u', $text, $stats);
        $statCount = count($stats[0]);

        $findings[] = $statCount >= 3
            ? F::pass('statistics', $d, 'Concrete numbers', "{$statCount} figures a passage could be built around.", 2)
            : F::warn('statistics', $d, 'Concrete numbers', $statCount === 0 ? 'No figures at all.' : "Only {$statCount} figures.", 'Answer engines quote specifics. A page of well-written generalities loses to a page with three numbers and a source.', 2);

        $external = 0;
        $links = $xpath->query('//a[@href]');
        $hosts = Plugin::getInstance()->urls->knownHosts();

        if ($links !== false) {
            foreach ($links as $link) {
                $href = $link instanceof DOMElement ? $link->getAttribute('href') : '';
                $host = parse_url($href, PHP_URL_HOST);

                if (is_string($host) && $host !== '' && !in_array(strtolower($host), $hosts, true)) {
                    $external++;
                }
            }
        }

        $findings[] = $external > 0
            ? F::pass('outbound-citations', $d, 'Cites its sources', "{$external} outbound links.", 1)
            : F::warn('outbound-citations', $d, 'Cites its sources', 'No outbound links.', 'Link the sources behind the claims. A page that cites is treated as more citable itself, and it is the difference between an assertion and a fact.', 1);

        $definitions = preg_match_all('/\b[A-Z][\w\- ]{2,40}\s+(?:is|are|means|refers to)\s+(?:a|an|the)\b/u', $text);

        $findings[] = $definitions > 0
            ? F::pass('definitions', $d, 'Definitional sentences', "{$definitions} sentence(s) define something outright.", 1)
            : F::warn('definitions', $d, 'Definitional sentences', 'Nothing on the page is stated as a definition.', 'Write at least one flat “X is a Y that does Z” sentence. It is the shape an engine reaches for when it needs one line about the subject.', 1);

        return $findings;
    }

    // ----------------------------------------------------------------- private

    private function parse(string $html): DOMDocument
    {
        $doc = new DOMDocument();
        $previous = libxml_use_internal_errors(true);

        // Real pages are not well-formed and it is not the audit's job to complain about that. The
        // prefixed meta forces UTF-8 regardless of what the document declares, which is the one
        // encoding bug that silently mangles every text-length check downstream.
        $doc->loadHTML('<?xml encoding="UTF-8">' . $html, LIBXML_NOWARNING | LIBXML_NOERROR);

        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        return $doc;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function jsonLd(DOMXPath $xpath): array
    {
        $blocks = [];
        $nodes = $xpath->query('//script[translate(@type,"ABCDEFGHIJKLMNOPQRSTUVWXYZ","abcdefghijklmnopqrstuvwxyz")="application/ld+json"]');

        if ($nodes === false) {
            return [];
        }

        foreach ($nodes as $node) {
            // A script element holds raw text: entities in it are never decoded, so the content is
            // taken verbatim rather than through anything that would try.
            $decoded = json_decode(trim((string)$node->textContent), true);

            if (!is_array($decoded)) {
                continue;
            }

            // A single object, an @graph, or a bare array — all three are common in the wild.
            foreach ($this->flattenJsonLd($decoded) as $item) {
                $blocks[] = $item;
            }
        }

        return $blocks;
    }

    /**
     * @param array<mixed> $data
     * @return list<array<string, mixed>>
     */
    private function flattenJsonLd(array $data): array
    {
        if (isset($data['@graph']) && is_array($data['@graph'])) {
            $out = [];

            foreach ($data['@graph'] as $item) {
                if (is_array($item)) {
                    $out = array_merge($out, $this->flattenJsonLd($item));
                }
            }

            return $out;
        }

        if (array_is_list($data)) {
            $out = [];

            foreach ($data as $item) {
                if (is_array($item)) {
                    $out = array_merge($out, $this->flattenJsonLd($item));
                }
            }

            return $out;
        }

        return [$data];
    }

    /**
     * @param list<array<string, mixed>> $jsonLd
     */
    private function pluck(array $jsonLd, string $key): mixed
    {
        foreach ($jsonLd as $block) {
            if (isset($block[$key]) && $block[$key] !== '' && $block[$key] !== []) {
                return $block[$key];
            }
        }

        return null;
    }

    /**
     * @param list<array<string, mixed>> $jsonLd
     * @return list<string>
     */
    private function types(array $jsonLd): array
    {
        $types = [];

        foreach ($jsonLd as $block) {
            $type = $block['@type'] ?? null;

            foreach (is_array($type) ? $type : [$type] as $one) {
                if (is_string($one) && $one !== '') {
                    $types[$one] = true;
                }
            }
        }

        return array_keys($types);
    }

    /**
     * @param list<array<string, mixed>> $jsonLd
     * @param list<string> $wanted
     */
    private function hasType(array $jsonLd, array $wanted): bool
    {
        return array_intersect($this->types($jsonLd), $wanted) !== [];
    }

    /**
     * The page's readable text, with the furniture removed.
     */
    private function mainText(DOMXPath $xpath): string
    {
        foreach (['//main', '//article', '//body'] as $selector) {
            $nodes = $xpath->query($selector);

            if ($nodes === false || $nodes->length === 0) {
                continue;
            }

            $node = $nodes->item(0);

            if (!$node instanceof DOMElement) {
                continue;
            }

            $copy = new DOMDocument();
            $copy->appendChild($copy->importNode($node, true));

            // Scripts and styles are text content as far as the DOM is concerned, and counting them
            // as words inflates every length check on the page. `iterator_to_array` first: a
            // DOMNodeList is live, so removing nodes while iterating it skips every other match.
            $copyXpath = new DOMXPath($copy);
            $junk = $copyXpath->query('//script|//style|//noscript|//template');

            foreach ($junk !== false ? iterator_to_array($junk) : [] as $element) {
                $element->parentNode?->removeChild($element);
            }

            return trim((string)preg_replace('/\s+/u', ' ', (string)$copy->textContent));
        }

        return '';
    }

    private function count(DOMXPath $xpath, string $expression): int
    {
        $nodes = $xpath->query($expression);

        return $nodes === false ? 0 : $nodes->length;
    }
}
