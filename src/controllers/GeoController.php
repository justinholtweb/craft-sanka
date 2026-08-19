<?php

declare(strict_types=1);

namespace justinholtweb\sanka\controllers;

use Craft;
use craft\elements\Entry;
use craft\web\Controller;
use justinholtweb\sanka\errors\SankaException;
use justinholtweb\sanka\models\Edition;
use justinholtweb\sanka\models\GeoFinding;
use justinholtweb\sanka\Plugin;
use Throwable;
use yii\web\ForbiddenHttpException;
use yii\web\Response;

/**
 * The GEO screen: what a model is handed, and whether a page is worth quoting.
 */
class GeoController extends Controller
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

        return $this->renderTemplate('sanka/geo', [
            'title' => Craft::t('sanka', 'GEO'),
            'settings' => $settings,
            'sections' => $plugin->llms->sections(),
            'dimensions' => GeoFinding::DIMENSIONS,
            'canManage' => Craft::$app->getUser()->checkPermission(Plugin::PERMISSION_MANAGE_GEO),
        ]);
    }

    /**
     * Preview the generated file without publishing it.
     */
    public function actionLlms(): Response
    {
        $full = (bool)$this->request->getParam('full', false);
        $siteId = (int)$this->request->getParam('siteId', Craft::$app->getSites()->getCurrentSite()->id);
        $llms = Plugin::getInstance()->llms;

        $body = $full ? $llms->full($siteId) : $llms->map($siteId);

        if ($this->request->getAcceptsJson()) {
            return $this->asJson(['success' => true, 'body' => $body, 'bytes' => strlen($body)]);
        }

        $this->response->format = Response::FORMAT_RAW;
        $this->response->getHeaders()->set('Content-Type', 'text/plain; charset=utf-8');
        $this->response->data = $body;

        return $this->response;
    }

    /**
     * Audit a page.
     *
     * Accepts an entry, a URL on one of this install's sites, or pasted HTML. Nothing else — the
     * URL is checked against Craft's own site configuration before it is fetched.
     */
    public function actionAudit(): Response
    {
        $this->requirePostRequest();

        $plugin = Plugin::getInstance();
        $entryId = (int)$this->request->getBodyParam('entryId', 0);
        $url = trim((string)$this->request->getBodyParam('url', ''));
        $html = (string)$this->request->getBodyParam('html', '');

        try {
            if ($html !== '') {
                $report = $plugin->geo->audit($html, $url !== '' ? $url : Craft::t('sanka', 'pasted HTML'));
            } elseif ($entryId > 0) {
                $entry = Entry::find()->id($entryId)->status(null)->one();

                if (!$entry instanceof Entry) {
                    return $this->asFailure(Craft::t('sanka', 'No such entry.'));
                }

                $report = $plugin->geo->auditElement($entry);
            } elseif ($url !== '') {
                $report = $plugin->geo->auditUrl($url);
            } else {
                return $this->asFailure(Craft::t('sanka', 'Give the audit an entry, a URL, or some HTML.'));
            }
        } catch (SankaException $e) {
            return $this->asFailure($e->getMessage());
        } catch (Throwable $e) {
            Craft::error("Sanka audit failed: {$e->getMessage()}", Plugin::LOG_CATEGORY);

            return $this->asFailure(Craft::t('sanka', 'The audit could not be completed: {message}', ['message' => $e->getMessage()]));
        }

        $html = $this->getView()->renderTemplate('sanka/_components/report', [
            'report' => $report,
            'dimensions' => GeoFinding::DIMENSIONS,
        ]);

        if ($this->request->getAcceptsJson()) {
            return $this->asJson([
                'success' => true,
                'score' => $report->score(),
                'scores' => $report->scores(),
                'counts' => $report->counts(),
                'html' => $html,
            ]);
        }

        return $this->renderTemplate('sanka/geo', [
            'title' => Craft::t('sanka', 'GEO'),
            'settings' => $plugin->getSettings(),
            'sections' => $plugin->llms->sections(),
            'dimensions' => GeoFinding::DIMENSIONS,
            'report' => $report,
            'canManage' => Craft::$app->getUser()->checkPermission(Plugin::PERMISSION_MANAGE_GEO),
        ]);
    }
}
