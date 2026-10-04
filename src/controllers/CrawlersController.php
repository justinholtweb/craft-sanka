<?php

declare(strict_types=1);

namespace justinholtweb\sanka\controllers;

use Craft;
use craft\web\Controller;
use justinholtweb\sanka\models\CrawlerAgent;
use justinholtweb\sanka\models\Edition;
use justinholtweb\sanka\Plugin;
use yii\web\ForbiddenHttpException;
use yii\web\Response;

/**
 * Who may read the site, and who did.
 */
class CrawlersController extends Controller
{
    public function beforeAction($action): bool
    {
        $this->requirePermission(Plugin::PERMISSION_VIEW);

        if (!Edition::allowsGeo(Plugin::getInstance()->isPro())) {
            throw new ForbiddenHttpException(Craft::t('sanka', 'The GEO features are a Pro feature.'));
        }

        return parent::beforeAction($action);
    }

    public function actionIndex(): Response
    {
        $plugin = Plugin::getInstance();
        $settings = $plugin->getSettings();

        $grouped = [];

        foreach ([CrawlerAgent::PURPOSE_SEARCH, CrawlerAgent::PURPOSE_USER, CrawlerAgent::PURPOSE_TRAINING] as $purpose) {
            $grouped[$purpose] = CrawlerAgent::ofPurpose($purpose);
        }

        return $this->renderTemplate('sanka/crawlers', [
            'title' => Craft::t('sanka', 'AI crawlers'),
            'grouped' => $grouped,
            'settings' => $settings,
            'summary' => $settings->crawlerLogEnabled ? $plugin->crawlers->summary(30) : [],
            'robots' => $plugin->crawlers->robotsTxt(),
            'staticRobots' => $plugin->crawlers->staticRobotsPath(),
            'canChangePolicy' => self::canChangePolicy(),
        ]);
    }

    /**
     * The policy and the extra robots.txt lines are plugin settings, which are project config — so
     * changing them is an admin's job on an environment that allows admin changes, as Craft's own
     * settings are. Before 5.0.2 the GEO permission was enough: a non-admin could change project
     * config on a development machine, and in production the save threw.
     */
    public static function canChangePolicy(): bool
    {
        return Craft::$app->getUser()->getIsAdmin() && Craft::$app->getConfig()->getGeneral()->allowAdminChanges;
    }

    public function actionPolicy(): Response
    {
        $this->requirePostRequest();
        $this->requireAdmin();

        $plugin = Plugin::getInstance();
        $settings = $plugin->getSettings();
        $posted = (array)$this->request->getBodyParam('policy', []);

        $policy = [];

        foreach (CrawlerAgent::all() as $agent) {
            $value = (string)($posted[$agent->token] ?? CrawlerAgent::POLICY_ALLOW);
            $policy[$agent->token] = $value === CrawlerAgent::POLICY_BLOCK
                ? CrawlerAgent::POLICY_BLOCK
                : CrawlerAgent::POLICY_ALLOW;
        }

        $settings->crawlerPolicy = $policy;
        $settings->robotsExtra = (string)$this->request->getBodyParam('robotsExtra', $settings->robotsExtra);

        // The **whole** settings model is written back, never just the changed keys. A partial array
        // handed to `savePluginSettings()` replaces the stored settings rather than merging into
        // them, so saving one field this way would silently clear every other one.
        if (!Craft::$app->getPlugins()->savePluginSettings($plugin, $settings->toArray())) {
            return $this->asModelFailure($settings, Craft::t('sanka', 'Could not save the crawler policy.'), 'settings');
        }

        return $this->asSuccess(Craft::t('sanka', 'Crawler policy saved.'), ['robots' => $plugin->crawlers->robotsTxt()]);
    }

    public function actionLog(): Response
    {
        $plugin = Plugin::getInstance();
        $agent = (string)$this->request->getParam('agent', '');

        return $this->renderTemplate('sanka/crawler-log', [
            'title' => Craft::t('sanka', 'AI crawler log'),
            'rows' => $plugin->crawlers->recent(200, $agent !== '' ? $agent : null),
            'summary' => $plugin->crawlers->summary(30),
            'topPages' => $plugin->crawlers->topPages(30, 25),
            'agent' => $agent,
            'settings' => $plugin->getSettings(),
            'canManage' => Craft::$app->getUser()->checkPermission(Plugin::PERMISSION_MANAGE_GEO),
        ]);
    }

    /**
     * Forward-confirmed reverse DNS over the rows nothing has checked yet.
     */
    public function actionVerify(): Response
    {
        $this->requirePostRequest();
        $this->requirePermission(Plugin::PERMISSION_MANAGE_GEO);

        $counts = Plugin::getInstance()->crawlers->verifyPending(200);

        return $this->asSuccess(Craft::t('sanka', '{checked} checked · {verified} genuine · {spoofed} forged · {unknown} unverifiable', $counts));
    }
}
