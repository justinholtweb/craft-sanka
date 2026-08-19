<?php

declare(strict_types=1);

namespace justinholtweb\sanka\services;

use craft\base\Component;
use justinholtweb\sanka\engines\EngineInterface;
use justinholtweb\sanka\engines\GoogleEngine;
use justinholtweb\sanka\engines\IndexNowEngine;
use justinholtweb\sanka\engines\SitemapEngine;
use justinholtweb\sanka\http\GuzzleHttpClient;
use justinholtweb\sanka\http\HttpClientInterface;
use justinholtweb\sanka\models\Edition;
use justinholtweb\sanka\Plugin;

/**
 * The engine registry.
 *
 * Engines are built lazily and cached, and the transport is settable, which is the whole reason the
 * integration checks can exercise the real submission path — dispatcher, ledger, quota, backoff,
 * result interpretation — without a single outbound request.
 */
class Engines extends Component
{
    private ?HttpClientInterface $http = null;

    /** @var array<string, EngineInterface>|null */
    private ?array $engines = null;

    public function getHttpClient(): HttpClientInterface
    {
        return $this->http ??= new GuzzleHttpClient();
    }

    /**
     * Swap the transport. Rebuilds the registry, because the engines hold the client they were
     * built with.
     */
    public function setHttpClient(HttpClientInterface $client): void
    {
        $this->http = $client;
        $this->engines = null;
    }

    /**
     * Every engine Sanka knows about, in the order the control panel shows them.
     *
     * @return array<string, EngineInterface>
     */
    public function getAll(): array
    {
        if ($this->engines !== null) {
            return $this->engines;
        }

        $settings = Plugin::getInstance()->getSettings();
        $http = $this->getHttpClient();

        $indexNow = new IndexNowEngine($settings, $http);

        return $this->engines = [
            GoogleEngine::HANDLE => new GoogleEngine($settings, $http),
            IndexNowEngine::HANDLE => $indexNow,
            SitemapEngine::HANDLE => new SitemapEngine($settings, $http, $indexNow),
        ];
    }

    public function get(string $handle): ?EngineInterface
    {
        return $this->getAll()[$handle] ?? null;
    }

    public function getGoogle(): GoogleEngine
    {
        /** @var GoogleEngine */
        return $this->getAll()[GoogleEngine::HANDLE];
    }

    public function getIndexNow(): IndexNowEngine
    {
        /** @var IndexNowEngine */
        return $this->getAll()[IndexNowEngine::HANDLE];
    }

    public function getSitemap(): SitemapEngine
    {
        /** @var SitemapEngine */
        return $this->getAll()[SitemapEngine::HANDLE];
    }

    /**
     * Engines this edition may run at all.
     *
     * A downgrade, not a refusal: a lapsed Pro licence keeps Google and IndexNow working and simply
     * stops resubmitting sitemaps. Nothing in the settings is touched, so upgrading restores exactly
     * what was configured.
     *
     * @return array<string, EngineInterface>
     */
    public function getAvailable(): array
    {
        $isPro = Plugin::getInstance()->isPro();

        return array_filter(
            $this->getAll(),
            static fn(EngineInterface $engine): bool => $engine->handle() !== SitemapEngine::HANDLE || Edition::allowsSitemaps($isPro),
        );
    }

    /**
     * Available and switched on.
     *
     * @return array<string, EngineInterface>
     */
    public function getEnabled(): array
    {
        return array_filter($this->getAvailable(), static fn(EngineInterface $e): bool => $e->isEnabled());
    }

    /**
     * Available, switched on, and actually able to make a call.
     *
     * @return array<string, EngineInterface>
     */
    public function getUsable(): array
    {
        return array_filter($this->getEnabled(), static fn(EngineInterface $e): bool => $e->isConfigured());
    }

    /**
     * @return list<string>
     */
    public function getUsableHandles(): array
    {
        return array_keys($this->getUsable());
    }

    /**
     * Reset the cache. Called after settings are saved, since every engine reads them at
     * construction.
     */
    public function reset(): void
    {
        $this->engines = null;
    }
}
