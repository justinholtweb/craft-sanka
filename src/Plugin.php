<?php

declare(strict_types=1);

namespace justinholtweb\sanka;

use Craft;
use craft\base\Element;
use craft\base\Model;
use craft\base\Plugin as BasePlugin;
use craft\elements\Entry;
use craft\events\DefineHtmlEvent;
use craft\events\ElementEvent;
use craft\events\RegisterUrlRulesEvent;
use craft\events\RegisterUserPermissionsEvent;
use craft\helpers\Queue;
use craft\services\Elements;
use craft\services\Gc;
use craft\services\UserPermissions;
use craft\web\Application as WebApplication;
use craft\web\twig\variables\CraftVariable;
use craft\web\UrlManager;
use justinholtweb\sanka\jobs\DrainJob;
use justinholtweb\sanka\models\Edition;
use justinholtweb\sanka\models\Rule;
use justinholtweb\sanka\models\Settings;
use justinholtweb\sanka\records\SubmissionRecord;
use justinholtweb\sanka\services\Crawlers;
use justinholtweb\sanka\services\Dispatcher;
use justinholtweb\sanka\services\Engines;
use justinholtweb\sanka\services\Geo;
use justinholtweb\sanka\services\Llms;
use justinholtweb\sanka\services\Quota;
use justinholtweb\sanka\services\Rules;
use justinholtweb\sanka\services\Submissions;
use justinholtweb\sanka\services\Urls;
use justinholtweb\sanka\twig\SankaVariable;
use yii\base\Event;

/**
 * Sanka — instant indexing and GEO.
 *
 * Waiting to be crawled is the slowest part of publishing, and for the answer engines it is often
 * not a wait at all: they never come. Sanka closes both gaps. It pushes URLs to Google's Indexing
 * API and to IndexNow the moment they change, and it manages the AI half of the web explicitly —
 * who may crawl, what they are given, who actually turned up, and whether a page is written in a
 * shape an answer engine can quote.
 *
 * Named for instant coffee, which is the joke, and which also gives the dry-run setting its
 * character: Sanka can be run decaffeinated, recording exactly what it *would* have sent while
 * nothing at all leaves the server.
 *
 * @property-read Engines $engines
 * @property-read Submissions $submissions
 * @property-read Dispatcher $dispatcher
 * @property-read Quota $quota
 * @property-read Rules $rules
 * @property-read Urls $urls
 * @property-read Crawlers $crawlers
 * @property-read Llms $llms
 * @property-read Geo $geo
 * @property-read Settings $settings
 *
 * @method Settings getSettings()
 */
class Plugin extends BasePlugin
{
    public const EDITION_LITE = 'lite';
    public const EDITION_PRO = 'pro';

    public const PERMISSION_VIEW = 'sanka:view';
    public const PERMISSION_SUBMIT = 'sanka:submit';
    public const PERMISSION_MANAGE_GEO = 'sanka:manageGeo';

    /** Log category used by everything in the plugin. */
    public const LOG_CATEGORY = 'sanka';

    public string $schemaVersion = '1.0.0';

    public bool $hasCpSection = true;

    public bool $hasCpSettings = true;

    /**
     * URLs queued during this request, so one drain job is pushed at the end rather than one per
     * saved entry. A bulk operation saving three hundred entries should schedule one drain.
     *
     * @var array<string, true>
     */
    private array $touched = [];

    private bool $drainScheduled = false;

    public static function editions(): array
    {
        return [self::EDITION_LITE, self::EDITION_PRO];
    }

    public static function config(): array
    {
        return [
            'components' => [
                'engines' => Engines::class,
                'submissions' => Submissions::class,
                'dispatcher' => Dispatcher::class,
                'quota' => Quota::class,
                'rules' => Rules::class,
                'urls' => Urls::class,
                'crawlers' => Crawlers::class,
                'llms' => Llms::class,
                'geo' => Geo::class,
            ],
        ];
    }

    public function init(): void
    {
        parent::init();

        $this->registerRoutes();
        $this->registerPermissions();
        $this->registerTwig();
        $this->registerGarbageCollection();

        // Everything below reads settings or elements, so none of it may run while Craft is still
        // installing itself or applying project config.
        Craft::$app->onInit(function() {
            $this->registerElementEvents();
            $this->registerCrawlerLogging();
            $this->registerEntrySidebar();
        });
    }

    /**
     * Whether the Pro feature set is available.
     *
     * Every edition check goes through here rather than calling `is()` directly, so the Lite/Pro
     * boundary is auditable in one place.
     */
    public function isPro(): bool
    {
        return $this->is(self::EDITION_PRO, '>=');
    }

    public function getCpNavItem(): ?array
    {
        $item = parent::getCpNavItem();
        $item['label'] = Craft::t('sanka', 'Sanka');

        $item['subnav'] = [
            'overview' => ['label' => Craft::t('sanka', 'Overview'), 'url' => 'sanka'],
            'submit' => ['label' => Craft::t('sanka', 'Submit'), 'url' => 'sanka/submit'],
            'log' => ['label' => Craft::t('sanka', 'Submissions'), 'url' => 'sanka/log'],
        ];

        if (Edition::allowsGeo($this->isPro())) {
            $item['subnav']['crawlers'] = ['label' => Craft::t('sanka', 'AI crawlers'), 'url' => 'sanka/crawlers'];
            $item['subnav']['geo'] = ['label' => Craft::t('sanka', 'GEO'), 'url' => 'sanka/geo'];
        }

        if (Craft::$app->getUser()->getIsAdmin()) {
            $item['subnav']['settings'] = ['label' => Craft::t('sanka', 'Settings'), 'url' => 'settings/plugins/sanka'];
        }

        return $item;
    }

    protected function createSettingsModel(): ?Model
    {
        return new Settings();
    }

    protected function settingsHtml(): ?string
    {
        return Craft::$app->getView()->renderTemplate('sanka/settings', [
            'plugin' => $this,
            'settings' => $this->getSettings(),
            'engines' => $this->engines->getAll(),
            'isPro' => $this->isPro(),
            'editionProblems' => Edition::problems($this->getSettings(), $this->isPro()),
            'staticRobots' => $this->crawlers->staticRobotsPath(),
            'cpDisallows' => $this->crawlers->cpDisallows(),
            'detectedSitemaps' => $this->engines->getSitemap()->sitemapUrls(),
            'sitemapSource' => $this->engines->getSitemap()->sitemapSource(),
            'sections' => Craft::$app->getEntries()->getAllSections(),
            'sites' => Craft::$app->getSites()->getAllSites(),
        ]);
    }

    /**
     * Settings are read at engine construction, so a saved change has to invalidate the registry or
     * the rest of the request keeps using the old credentials.
     */
    public function afterSaveSettings(): void
    {
        parent::afterSaveSettings();
        $this->engines->reset();
        $this->llms->invalidate();
    }

    // ------------------------------------------------------------------ wiring

    private function registerRoutes(): void
    {
        Event::on(UrlManager::class, UrlManager::EVENT_REGISTER_CP_URL_RULES, function(RegisterUrlRulesEvent $event) {
            $event->rules += [
                'sanka' => 'sanka/overview/index',
                'sanka/submit' => 'sanka/submit/index',
                'sanka/log' => 'sanka/log/index',
                'sanka/log/<submissionId:\d+>' => 'sanka/log/detail',
                'sanka/crawlers' => 'sanka/crawlers/index',
                'sanka/crawlers/policy' => 'sanka/crawlers/policy',
                'sanka/crawlers/log' => 'sanka/crawlers/log',
                'sanka/geo' => 'sanka/geo/index',
                'sanka/geo/audit' => 'sanka/geo/audit',
                'sanka/geo/llms' => 'sanka/geo/llms',
            ];
        });

        Event::on(UrlManager::class, UrlManager::EVENT_REGISTER_SITE_URL_RULES, function(RegisterUrlRulesEvent $event) {
            $settings = $this->getSettings();

            // The IndexNow key file. Served from a route rather than written to the web root, so
            // there is no file to forget to deploy and nothing to go stale when the key changes.
            if ($settings->indexNowEnabled && trim($settings->indexNowKey) !== '') {
                $event->rules[trim($settings->indexNowKey) . '.txt'] = 'sanka/public/key';
            }

            if (!Edition::allowsGeo($this->isPro())) {
                return;
            }

            if ($settings->llmsEnabled) {
                $event->rules['llms.txt'] = 'sanka/public/llms';

                if ($settings->llmsFullEnabled) {
                    $event->rules['llms-full.txt'] = 'sanka/public/llms-full';
                }
            }

            if ($settings->robotsEnabled) {
                $event->rules['robots.txt'] = 'sanka/public/robots';
            }
        });
    }

    private function registerPermissions(): void
    {
        Event::on(UserPermissions::class, UserPermissions::EVENT_REGISTER_PERMISSIONS, function(RegisterUserPermissionsEvent $event) {
            $event->permissions[] = [
                'heading' => Craft::t('sanka', 'Sanka'),
                'permissions' => [
                    self::PERMISSION_VIEW => [
                        'label' => Craft::t('sanka', 'View submissions and crawler activity'),
                        'nested' => [
                            self::PERMISSION_SUBMIT => ['label' => Craft::t('sanka', 'Submit URLs and retry submissions')],
                            self::PERMISSION_MANAGE_GEO => ['label' => Craft::t('sanka', 'Verify AI crawler log entries')],
                        ],
                    ],
                ],
            ];
        });
    }

    private function registerTwig(): void
    {
        Event::on(CraftVariable::class, CraftVariable::EVENT_INIT, function(Event $event) {
            $event->sender->set('sanka', SankaVariable::class);
        });
    }

    private function registerGarbageCollection(): void
    {
        Event::on(Gc::class, Gc::EVENT_RUN, function() {
            $settings = $this->getSettings();

            $this->submissions->prune($settings->retentionDays);
            $this->crawlers->prune($settings->crawlerRetentionDays);

            // Quota history is kept longer than submissions on purpose: the gauge on the overview
            // screen is the only place a “we have been at the ceiling for a month” pattern shows up,
            // and it costs one row per engine per day.
            $this->quota->prune(max(365, $settings->retentionDays));
        });
    }

    /**
     * Turn entry changes into ledger rows.
     *
     * Deliberately three plain listeners rather than anything clever. `BulkOpEvent::defer()` only
     * fires while a bulk operation is in progress, so a rule that must see every save cannot be
     * built on it — and the expensive part here is the drain, which is already deferred to the end
     * of the request by {@see self::scheduleDrain()}.
     */
    private function registerElementEvents(): void
    {
        Event::on(Elements::class, Elements::EVENT_AFTER_SAVE_ELEMENT, function(ElementEvent $event) {
            $element = $event->element;

            if (!$element instanceof Entry || $element->getIsDraft() || $element->getIsRevision() || $element->resaving) {
                return;
            }

            // A propagating save is the same content arriving on another site. That site's URL is a
            // different URL and does want submitting, so this is not filtered out — but the
            // ledger's deduplication is what stops it becoming N submissions of the same page.
            $live = $this->urls->isLiveEntry($element);
            $event_ = $event->isNew ? Rule::EVENT_CREATE : Rule::EVENT_UPDATE;

            // An entry that has just stopped being live is a removal, not an update. Submitting it
            // as an update asks an engine to index a 404.
            if (!$live) {
                $this->queueFor($element, Rule::EVENT_DELETE, SubmissionRecord::TYPE_DELETED, Submissions::REASON_SAVE);

                return;
            }

            $this->queueFor($element, $event_, SubmissionRecord::TYPE_UPDATED, Submissions::REASON_SAVE);
        });

        Event::on(Elements::class, Elements::EVENT_AFTER_DELETE_ELEMENT, function(ElementEvent $event) {
            if ($event->element instanceof Entry) {
                $this->queueFor($event->element, Rule::EVENT_DELETE, SubmissionRecord::TYPE_DELETED, Submissions::REASON_DELETE);
            }
        });

        Event::on(Elements::class, Elements::EVENT_AFTER_RESTORE_ELEMENT, function(ElementEvent $event) {
            if ($event->element instanceof Entry && $this->urls->isLiveEntry($event->element)) {
                $this->queueFor($event->element, Rule::EVENT_UPDATE, SubmissionRecord::TYPE_UPDATED, Submissions::REASON_SAVE);
            }
        });
    }

    /**
     * Record requests from known AI agents.
     *
     * `EVENT_AFTER_REQUEST` rather than a response filter, because the status code is settled by
     * then and because this must never be able to change what the visitor receives.
     */
    private function registerCrawlerLogging(): void
    {
        if (!$this->getSettings()->crawlerLogEnabled || !Edition::allowsGeo($this->isPro())) {
            return;
        }

        $request = Craft::$app->getRequest();

        if ($request->getIsConsoleRequest() || $request->getIsCpRequest()) {
            return;
        }

        Event::on(WebApplication::class, WebApplication::EVENT_AFTER_REQUEST, function() {
            $request = Craft::$app->getRequest();

            if ($request->getIsConsoleRequest() || $request->getIsCpRequest() || $request->getIsActionRequest()) {
                return;
            }

            $userAgent = (string)$request->getUserAgent();
            $agent = $this->crawlers->detect($userAgent);

            if ($agent === null) {
                return;
            }

            $this->crawlers->record(
                $agent,
                $request->getAbsoluteUrl(),
                Craft::$app->getResponse()->getStatusCode(),
                $userAgent,
                $request->getUserIP(),
                Craft::$app->getSites()->getCurrentSite()->id,
            );
        });
    }

    /**
     * A panel on the entry edit screen: what Sanka last did with this URL, and what it would do now.
     */
    private function registerEntrySidebar(): void
    {
        Event::on(Entry::class, Element::EVENT_DEFINE_SIDEBAR_HTML, function(DefineHtmlEvent $event) {
            $entry = $event->sender;

            if (!$entry instanceof Entry || $entry->getIsDraft() || $entry->getIsRevision() || $entry->id === null) {
                return;
            }

            $url = $this->urls->forElement($entry);

            if ($url === null) {
                return;
            }

            $event->html .= Craft::$app->getView()->renderTemplate('sanka/_components/sidebar', [
                'entry' => $entry,
                'url' => $url,
                'history' => $this->submissions->history($url, 5),
                'engines' => $this->rules->enginesFor($entry, Rule::EVENT_UPDATE),
                'canSubmit' => Craft::$app->getUser()->checkPermission(self::PERMISSION_SUBMIT),
            ]);
        });
    }

    // ----------------------------------------------------------------- private

    private function queueFor(Entry $entry, string $event, string $type, string $reason): void
    {
        $engines = $this->rules->enginesFor($entry, $event);

        if ($engines === null || $engines === []) {
            return;
        }

        // A URL is queued once per request no matter how many times the entry is saved during it —
        // a propagating save, a Matrix resave and a plugin's own follow-up save are all one change
        // as far as an engine is concerned.
        $url = $this->urls->forElement($entry);

        if ($url === null) {
            return;
        }

        $key = $type . '|' . $url;

        if (isset($this->touched[$key])) {
            return;
        }

        $this->touched[$key] = true;

        $rows = $this->submissions->queueElement($entry, $type, $reason, $engines);

        if ($rows !== []) {
            $this->llms->invalidate();
            $this->scheduleDrain();
        }
    }

    /**
     * Push exactly one drain job per request, at the end of it.
     *
     * A bulk operation touching three hundred entries should schedule one job, not three hundred,
     * and the job must not be pushed until the transaction that saved them has committed.
     */
    private function scheduleDrain(): void
    {
        if ($this->drainScheduled) {
            return;
        }

        $this->drainScheduled = true;

        Craft::$app->onAfterRequest(static function() {
            Queue::push(new DrainJob());
        });
    }
}
