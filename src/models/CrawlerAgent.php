<?php

declare(strict_types=1);

namespace justinholtweb\sanka\models;

use Craft;

/**
 * One AI agent Sanka knows about.
 *
 * The registry exists because “block the AI bots” is advice that destroys traffic when followed
 * literally. Three different things share the label:
 *
 * - **training** crawlers collect text to train a model. Blocking them costs nothing today.
 * - **search** crawlers build the index an answer engine cites from. Blocking `OAI-SearchBot`
 *   removes the site from ChatGPT's search results while doing nothing at all about training.
 * - **user** fetchers retrieve a page because a person just asked about it. Blocking them means a
 *   reader who explicitly wanted this page gets told it could not be read.
 *
 * So every agent carries its purpose and a sentence saying what blocking it actually does, and the
 * control panel groups them that way. Nobody should have to hold this table in their head.
 */
final class CrawlerAgent
{
    public const PURPOSE_TRAINING = 'training';
    public const PURPOSE_SEARCH = 'search';
    public const PURPOSE_USER = 'user';

    public const POLICY_ALLOW = 'allow';
    public const POLICY_BLOCK = 'block';

    public function __construct(
        /** The `User-agent` token, exactly as it goes into robots.txt. */
        public readonly string $token,
        public readonly string $label,
        public readonly string $vendor,
        public readonly string $purpose,
        /** What blocking this one actually costs. Shown next to the toggle. */
        public readonly string $consequence,
        /** Substrings that identify this agent in a real `User-Agent` header, lowercased. */
        public readonly array $signatures = [],
        public readonly ?string $docsUrl = null,
    ) {
    }

    /**
     * Everything Sanka recognises, in the order the control panel shows it.
     *
     * @return list<self>
     */
    public static function all(): array
    {
        static $agents = null;

        return $agents ??= [
            // ---- Search: these are the ones that decide whether you appear in an AI answer ----
            new self(
                'OAI-SearchBot', 'OpenAI Search', 'OpenAI', self::PURPOSE_SEARCH,
                'Removes the site from ChatGPT Search results. Does not affect model training either way.',
                ['oai-searchbot'], 'https://platform.openai.com/docs/bots',
            ),
            new self(
                'ClaudeBot', 'ClaudeBot', 'Anthropic', self::PURPOSE_SEARCH,
                'Anthropic’s general crawler. Blocking it removes the site from what Claude can cite.',
                ['claudebot'], 'https://support.anthropic.com/en/articles/8896518',
            ),
            new self(
                'Claude-SearchBot', 'Claude Search', 'Anthropic', self::PURPOSE_SEARCH,
                'Indexes pages for Claude’s search results. Blocking it removes the site from them.',
                ['claude-searchbot'], 'https://support.anthropic.com/en/articles/8896518',
            ),
            new self(
                'PerplexityBot', 'PerplexityBot', 'Perplexity', self::PURPOSE_SEARCH,
                'Builds Perplexity’s index. Blocking it removes the site from Perplexity answers.',
                ['perplexitybot'], 'https://docs.perplexity.ai/guides/bots',
            ),
            new self(
                'DuckAssistBot', 'DuckAssist', 'DuckDuckGo', self::PURPOSE_SEARCH,
                'Feeds DuckDuckGo’s AI answers. Blocking it removes the site from them.',
                ['duckassistbot'], 'https://duckduckgo.com/duckduckgo-help-pages/results/duckassistbot/',
            ),
            new self(
                'YouBot', 'YouBot', 'You.com', self::PURPOSE_SEARCH,
                'Builds You.com’s index.',
                ['youbot'], 'https://about.you.com/youbot/',
            ),
            new self(
                'Applebot', 'Applebot', 'Apple', self::PURPOSE_SEARCH,
                'Powers Siri and Spotlight suggestions. This is the search crawler, not the training one.',
                ['applebot'], 'https://support.apple.com/en-us/119829',
            ),
            new self(
                'Amazonbot', 'Amazonbot', 'Amazon', self::PURPOSE_SEARCH,
                'Feeds Alexa’s answers and Amazon’s search products.',
                ['amazonbot'], 'https://developer.amazon.com/amazonbot',
            ),

            // ---- User-initiated: a person asked about this page, right now ----
            new self(
                'ChatGPT-User', 'ChatGPT (user request)', 'OpenAI', self::PURPOSE_USER,
                'Fetches a page because a ChatGPT user asked about it. Blocking it means that reader is told the page could not be read.',
                ['chatgpt-user'], 'https://platform.openai.com/docs/bots',
            ),
            new self(
                'Perplexity-User', 'Perplexity (user request)', 'Perplexity', self::PURPOSE_USER,
                'Fetches a page a Perplexity user followed. Blocking it breaks that reader’s link.',
                ['perplexity-user'], 'https://docs.perplexity.ai/guides/bots',
            ),
            new self(
                'Claude-User', 'Claude (user request)', 'Anthropic', self::PURPOSE_USER,
                'Fetches a page a Claude user asked about. Blocking it breaks that reader’s request.',
                ['claude-user'], 'https://support.anthropic.com/en/articles/8896518',
            ),

            // ---- Training: blocking these costs nothing today ----
            new self(
                'GPTBot', 'GPTBot', 'OpenAI', self::PURPOSE_TRAINING,
                'Collects text to train OpenAI’s models. Blocking it does not affect ChatGPT Search.',
                ['gptbot'], 'https://platform.openai.com/docs/bots',
            ),
            new self(
                'Google-Extended', 'Google-Extended', 'Google', self::PURPOSE_TRAINING,
                'Controls whether content trains Gemini. It is not a crawler and blocking it has no effect on Google Search ranking or indexing.',
                [], 'https://developers.google.com/search/docs/crawling-indexing/overview-google-crawlers',
            ),
            new self(
                'Applebot-Extended', 'Applebot-Extended', 'Apple', self::PURPOSE_TRAINING,
                'Controls whether content trains Apple Intelligence. Applebot keeps crawling for Siri and Spotlight regardless.',
                [], 'https://support.apple.com/en-us/119829',
            ),
            new self(
                'CCBot', 'Common Crawl', 'Common Crawl', self::PURPOSE_TRAINING,
                'Builds the open dataset that many models train on. Blocking it reaches more models than any other single entry here.',
                ['ccbot'], 'https://commoncrawl.org/ccbot',
            ),
            new self(
                'meta-externalagent', 'Meta External Agent', 'Meta', self::PURPOSE_TRAINING,
                'Collects text for Meta’s models.',
                ['meta-externalagent'], 'https://developers.facebook.com/docs/sharing/webmasters/web-crawlers/',
            ),
            new self(
                'Bytespider', 'Bytespider', 'ByteDance', self::PURPOSE_TRAINING,
                'ByteDance’s training crawler. Widely reported to be heavy-handed about rate.',
                ['bytespider'],
            ),
            new self(
                'anthropic-ai', 'anthropic-ai (legacy)', 'Anthropic', self::PURPOSE_TRAINING,
                'A legacy token kept for older robots.txt files. ClaudeBot is the current one.',
                ['anthropic-ai'],
            ),
            new self(
                'cohere-ai', 'Cohere', 'Cohere', self::PURPOSE_TRAINING,
                'Cohere’s crawler.',
                ['cohere-ai'],
            ),
            new self(
                'Diffbot', 'Diffbot', 'Diffbot', self::PURPOSE_TRAINING,
                'Builds a commercial knowledge graph that is resold as training and enrichment data.',
                ['diffbot'], 'https://docs.diffbot.com/docs/en/guides-diffbot-crawler',
            ),
            new self(
                'ImagesiftBot', 'ImageSift', 'Hive', self::PURPOSE_TRAINING,
                'Collects images for training. Text pages are largely unaffected.',
                ['imagesiftbot'], 'https://imagesift.com/about',
            ),
            new self(
                'Omgilibot', 'Webz.io', 'Webz.io', self::PURPOSE_TRAINING,
                'Collects text resold as a training dataset.',
                ['omgilibot', 'omgili'],
            ),
            new self(
                'Timpibot', 'Timpi', 'Timpi', self::PURPOSE_TRAINING,
                'A decentralised index sold for AI use.',
                ['timpibot'],
            ),
        ];
    }

    /**
     * @return array<string, self> keyed by token
     */
    public static function indexed(): array
    {
        static $indexed = null;

        if ($indexed === null) {
            $indexed = [];

            foreach (self::all() as $agent) {
                $indexed[$agent->token] = $agent;
            }
        }

        return $indexed;
    }

    public static function find(string $token): ?self
    {
        return self::indexed()[$token] ?? null;
    }

    /**
     * @return list<self>
     */
    public static function ofPurpose(string $purpose): array
    {
        return array_values(array_filter(self::all(), static fn(self $a): bool => $a->purpose === $purpose));
    }

    /**
     * The default policy for an agent Sanka has not been told about.
     *
     * Allow, uniformly. A plugin that silently starts blocking answer engines the day it is
     * installed would be doing something the operator never asked for, and the training crawlers
     * are the ones people actually want gone — which is a decision, not a default.
     */
    public static function defaultPolicy(): string
    {
        return self::POLICY_ALLOW;
    }

    public function purposeLabel(): string
    {
        return match ($this->purpose) {
            self::PURPOSE_SEARCH => Craft::t('sanka', 'Answer engine'),
            self::PURPOSE_USER => Craft::t('sanka', 'User request'),
            default => Craft::t('sanka', 'Model training'),
        };
    }

    /**
     * Whether blocking this agent removes the site from somewhere a reader would have seen it.
     */
    public function blockingCostsVisibility(): bool
    {
        return $this->purpose !== self::PURPOSE_TRAINING;
    }
}
