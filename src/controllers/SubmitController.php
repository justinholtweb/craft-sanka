<?php

declare(strict_types=1);

namespace justinholtweb\sanka\controllers;

use Craft;
use craft\elements\Entry;
use craft\web\Controller;
use justinholtweb\sanka\errors\SankaException;
use justinholtweb\sanka\models\Edition;
use justinholtweb\sanka\Plugin;
use justinholtweb\sanka\records\SubmissionRecord;
use justinholtweb\sanka\services\Submissions;
use Throwable;
use yii\web\Response;

/**
 * The console: paste URLs, choose engines, submit, and ask Google what it knows.
 */
class SubmitController extends Controller
{
    public function beforeAction($action): bool
    {
        $this->requirePermission(Plugin::PERMISSION_VIEW);

        return parent::beforeAction($action);
    }

    public function actionIndex(): Response
    {
        $plugin = Plugin::getInstance();

        return $this->renderTemplate('sanka/submit', [
            'title' => Craft::t('sanka', 'Submit'),
            'engines' => $plugin->engines->getAvailable(),
            'usable' => $plugin->engines->getUsableHandles(),
            'settings' => $plugin->getSettings(),
            'sections' => Craft::$app->getEntries()->getAllSections(),
            'canBulk' => Edition::allowsBulk($plugin->isPro()),
            'sitemaps' => $plugin->engines->getSitemap()->sitemapUrls(),
        ]);
    }

    /**
     * Queue whatever was pasted in, then drain immediately.
     *
     * Draining inline rather than through the queue is deliberate here and nowhere else: somebody
     * watching a screen having just pressed a button should see the answer, not a job appear.
     */
    public function actionUrls(): Response
    {
        $this->requirePostRequest();
        $this->requirePermission(Plugin::PERMISSION_SUBMIT);

        $plugin = Plugin::getInstance();
        $raw = (string)$this->request->getBodyParam('urls', '');
        $engines = (array)$this->request->getBodyParam('engines', []);
        $type = (string)$this->request->getBodyParam('type', SubmissionRecord::TYPE_UPDATED);

        if (!in_array($type, SubmissionRecord::TYPES, true)) {
            $type = SubmissionRecord::TYPE_UPDATED;
        }

        $urls = $this->parseUrls($raw);

        if ($urls === []) {
            return $this->asFailure(Craft::t('sanka', 'No URLs to submit.'));
        }

        $engines = array_values(array_filter(array_map('strval', $engines)));
        $queued = 0;

        foreach ($urls as $url) {
            $queued += count($plugin->submissions->queueUrl(
                $url,
                $engines !== [] ? $engines : null,
                $type,
                Submissions::REASON_CONSOLE,
            ));
        }

        $counts = $plugin->dispatcher->drain();

        return $this->asSuccess(
            Craft::t('sanka', '{queued} queued · {sent} sent · {skipped} skipped · {failed} failed', [
                'queued' => $queued,
                'sent' => $counts['sent'],
                'skipped' => $counts['skipped'],
                'failed' => $counts['failed'],
            ]),
            ['counts' => $counts, 'queued' => $queued, 'rows' => $this->recentRows(count($urls) * 3)],
        );
    }

    /**
     * Queue a whole section. Pro, because on a large site this is the button that spends a quota.
     */
    public function actionSection(): Response
    {
        $this->requirePostRequest();
        $this->requirePermission(Plugin::PERMISSION_SUBMIT);

        $plugin = Plugin::getInstance();

        if (!Edition::allowsBulk($plugin->isPro())) {
            return $this->asFailure(Craft::t('sanka', 'Bulk submission is a Pro feature.'));
        }

        $handle = (string)$this->request->getRequiredBodyParam('section');
        $engines = array_values(array_filter(array_map('strval', (array)$this->request->getBodyParam('engines', []))));

        $entries = Entry::find()
            ->section($handle)
            ->status(Entry::STATUS_LIVE)
            ->siteId('*')
            ->unique()
            ->all();

        $queued = 0;

        foreach ($entries as $entry) {
            $queued += count($plugin->submissions->queueElement(
                $entry,
                SubmissionRecord::TYPE_UPDATED,
                Submissions::REASON_BULK,
                $engines !== [] ? $engines : null,
            ));
        }

        return $this->asSuccess(Craft::t('sanka', '{queued} submissions queued from {count} entries. They will go out as quota allows.', [
            'queued' => $queued,
            'count' => count($entries),
        ]));
    }

    /**
     * Resubmit the sitemaps.
     */
    public function actionSitemaps(): Response
    {
        $this->requirePostRequest();
        $this->requirePermission(Plugin::PERMISSION_SUBMIT);

        $plugin = Plugin::getInstance();
        $engine = $plugin->engines->getSitemap();

        if (!Edition::allowsSitemaps($plugin->isPro())) {
            return $this->asFailure(Craft::t('sanka', 'Sitemap resubmission is a Pro feature.'));
        }

        $queued = 0;

        foreach ($engine->sitemapUrls() as $url) {
            if ($plugin->submissions->queue($url, $engine->handle(), SubmissionRecord::TYPE_UPDATED, Submissions::REASON_CONSOLE) !== null) {
                $queued++;
            }
        }

        $counts = $plugin->dispatcher->drain($engine->handle());

        return $this->asSuccess(Craft::t('sanka', '{queued} sitemaps queued, {sent} sent.', [
            'queued' => $queued,
            'sent' => $counts['sent'],
        ]));
    }

    /**
     * What Google last heard about a URL.
     *
     * The only read-only call in the plugin, and the only way to tell “Google accepted my
     * notification” from “Google acted on it”.
     */
    public function actionStatus(): Response
    {
        $this->requireAcceptsJson();
        $this->requirePermission(Plugin::PERMISSION_VIEW);

        $url = trim((string)$this->request->getRequiredParam('url'));
        $plugin = Plugin::getInstance();

        $problem = $plugin->urls->problem($url);

        if ($problem !== null) {
            return $this->asJson(['success' => false, 'error' => $problem]);
        }

        $google = $plugin->engines->getGoogle();

        if (!$google->isConfigured()) {
            return $this->asJson(['success' => false, 'error' => implode(' ', $google->problems())]);
        }

        try {
            $metadata = $google->metadata($url);
        } catch (SankaException|Throwable $e) {
            return $this->asJson(['success' => false, 'error' => $e->getMessage()]);
        }

        return $this->asJson([
            'success' => true,
            'known' => $metadata !== null,
            'metadata' => $metadata,
            'history' => array_map(static fn($row): array => [
                'engine' => $row->engine,
                'type' => $row->type,
                'status' => $row->status,
                'message' => $row->message,
                'date' => (string)$row->dateCreated,
            ], $plugin->submissions->history($url, 10)),
        ]);
    }

    // ----------------------------------------------------------------- private

    /**
     * @return list<string>
     */
    private function parseUrls(string $raw): array
    {
        $urls = [];

        foreach (preg_split('/[\r\n,]+/', $raw) ?: [] as $line) {
            $line = trim($line);

            if ($line !== '') {
                $urls[$line] = $line;
            }
        }

        return array_values($urls);
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function recentRows(int $limit): array
    {
        return array_map(static fn(SubmissionRecord $row): array => [
            'url' => (string)$row->url,
            'engine' => (string)$row->engine,
            'type' => (string)$row->type,
            'status' => (string)$row->status,
            'message' => (string)$row->message,
        ], SubmissionRecord::find()
            ->orderBy(['dateCreated' => SORT_DESC, 'id' => SORT_DESC])
            ->limit(max(5, $limit))
            ->all());
    }
}
