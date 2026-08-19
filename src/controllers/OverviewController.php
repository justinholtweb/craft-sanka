<?php

declare(strict_types=1);

namespace justinholtweb\sanka\controllers;

use craft\web\Controller;
use justinholtweb\sanka\models\Edition;
use justinholtweb\sanka\Plugin;
use yii\web\Response;

/**
 * The screen that answers “is this working, and if not, why not”.
 */
class OverviewController extends Controller
{
    public function beforeAction($action): bool
    {
        $this->requirePermission(Plugin::PERMISSION_VIEW);

        return parent::beforeAction($action);
    }

    public function actionIndex(): Response
    {
        $plugin = Plugin::getInstance();
        $isPro = $plugin->isPro();

        return $this->renderTemplate('sanka/overview', [
            'title' => 'Sanka',
            'engines' => $plugin->dispatcher->status(),
            'summary' => $plugin->submissions->summary(),
            'queued' => $plugin->submissions->pendingCount(),
            'latest' => \justinholtweb\sanka\records\SubmissionRecord::find()
                ->orderBy(['dateCreated' => SORT_DESC])
                ->limit(15)
                ->all(),
            'rules' => $plugin->rules->all(),
            'settings' => $plugin->getSettings(),
            'isPro' => $isPro,
            'geoAllowed' => Edition::allowsGeo($isPro),
            'crawlerSummary' => Edition::allowsGeo($isPro) && $plugin->getSettings()->crawlerLogEnabled
                ? $plugin->crawlers->summary(30)
                : [],
        ]);
    }

    /**
     * Drain the queue now, from the button on the overview screen.
     */
    public function actionDrain(): Response
    {
        $this->requirePostRequest();
        $this->requirePermission(Plugin::PERMISSION_SUBMIT);

        $counts = Plugin::getInstance()->dispatcher->drain();

        if ($this->request->getAcceptsJson()) {
            return $this->asJson(['success' => true, 'counts' => $counts]);
        }

        return $this->asSuccess(\Craft::t('sanka', '{sent} sent, {skipped} skipped, {failed} failed.', [
            'sent' => $counts['sent'],
            'skipped' => $counts['skipped'],
            'failed' => $counts['failed'],
        ]));
    }
}
