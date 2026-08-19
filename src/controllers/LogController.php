<?php

declare(strict_types=1);

namespace justinholtweb\sanka\controllers;

use Craft;
use craft\db\Query;
use craft\helpers\Db;
use craft\web\Controller;
use DateTime;
use justinholtweb\sanka\models\Edition;
use justinholtweb\sanka\Plugin;
use justinholtweb\sanka\records\SubmissionRecord;
use yii\web\NotFoundHttpException;
use yii\web\Response;

/**
 * The ledger, made readable.
 *
 * Filters exist for exactly the questions people actually ask of it: what failed, what got skipped
 * and why, and what happened to this one URL.
 */
class LogController extends Controller
{
    private const PAGE_SIZE = 50;

    public function beforeAction($action): bool
    {
        $this->requirePermission(Plugin::PERMISSION_VIEW);

        return parent::beforeAction($action);
    }

    public function actionIndex(): Response
    {
        $plugin = Plugin::getInstance();

        $status = (string)$this->request->getParam('status', '');
        $engine = (string)$this->request->getParam('engine', '');
        $search = trim((string)$this->request->getParam('search', ''));
        $page = max(1, (int)$this->request->getParam('page', 1));

        $query = $this->filtered($status, $engine, $search);
        $total = (int)(clone $query)->count();

        $rows = $query
            ->orderBy(['dateCreated' => SORT_DESC, 'id' => SORT_DESC])
            ->offset(($page - 1) * self::PAGE_SIZE)
            ->limit(self::PAGE_SIZE)
            ->all();

        return $this->renderTemplate('sanka/log', [
            'title' => Craft::t('sanka', 'Submissions'),
            'rows' => $rows,
            'total' => $total,
            'page' => $page,
            'pages' => (int)ceil($total / self::PAGE_SIZE),
            'status' => $status,
            'engine' => $engine,
            'search' => $search,
            'engines' => $plugin->engines->getAll(),
            'statuses' => SubmissionRecord::STATUSES,
            'canSubmit' => Craft::$app->getUser()->checkPermission(Plugin::PERMISSION_SUBMIT),
            'canExport' => Edition::allowsExport($plugin->isPro()),
        ]);
    }

    public function actionDetail(int $submissionId): Response
    {
        $row = SubmissionRecord::findOne($submissionId);

        if ($row === null) {
            throw new NotFoundHttpException('Submission not found.');
        }

        $plugin = Plugin::getInstance();

        return $this->renderTemplate('sanka/log-detail', [
            'title' => Craft::t('sanka', 'Submission'),
            'row' => $row,
            'history' => $plugin->submissions->history((string)$row->url, 50),
            'engine' => $plugin->engines->get((string)$row->engine),
            'canSubmit' => Craft::$app->getUser()->checkPermission(Plugin::PERMISSION_SUBMIT),
        ]);
    }

    /**
     * Put finished rows back in the queue and drain.
     */
    public function actionRetry(): Response
    {
        $this->requirePostRequest();
        $this->requirePermission(Plugin::PERMISSION_SUBMIT);

        $ids = array_values(array_filter(array_map('intval', (array)$this->request->getBodyParam('ids', []))));

        if ($ids === []) {
            $id = (int)$this->request->getBodyParam('submissionId', 0);

            if ($id > 0) {
                $ids = [$id];
            }
        }

        if ($ids === []) {
            return $this->asFailure(Craft::t('sanka', 'Nothing selected.'));
        }

        $plugin = Plugin::getInstance();
        $requeued = 0;

        /** @var SubmissionRecord $row */
        foreach (SubmissionRecord::find()->where(['id' => $ids])->all() as $row) {
            $plugin->submissions->requeue($row);
            $requeued++;
        }

        $counts = $plugin->dispatcher->drain();

        return $this->asSuccess(Craft::t('sanka', '{requeued} requeued · {sent} sent · {failed} failed', [
            'requeued' => $requeued,
            'sent' => $counts['sent'],
            'failed' => $counts['failed'],
        ]));
    }

    /**
     * The ledger as CSV. Pro.
     */
    public function actionExport(): Response
    {
        $plugin = Plugin::getInstance();

        if (!Edition::allowsExport($plugin->isPro())) {
            return $this->asFailure(Craft::t('sanka', 'Export is a Pro feature.'));
        }

        $rows = $this->filtered(
            (string)$this->request->getParam('status', ''),
            (string)$this->request->getParam('engine', ''),
            trim((string)$this->request->getParam('search', '')),
        )
            ->orderBy(['dateCreated' => SORT_DESC])
            ->limit(10000)
            ->all();

        $handle = fopen('php://temp', 'r+');
        fputcsv($handle, ['date', 'url', 'engine', 'type', 'status', 'code', 'attempts', 'reason', 'message']);

        foreach ($rows as $row) {
            fputcsv($handle, [
                (string)$row['dateCreated'],
                (string)$row['url'],
                (string)$row['engine'],
                (string)$row['type'],
                (string)$row['status'],
                (string)($row['statusCode'] ?? ''),
                (string)$row['attempts'],
                (string)$row['reason'],
                (string)($row['message'] ?? ''),
            ]);
        }

        rewind($handle);
        $csv = (string)stream_get_contents($handle);
        fclose($handle);

        return $this->response->sendContentAsFile($csv, 'sanka-submissions-' . (new DateTime())->format('Y-m-d') . '.csv', [
            'mimeType' => 'text/csv',
        ]);
    }

    // ----------------------------------------------------------------- private

    private function filtered(string $status, string $engine, string $search): Query
    {
        $query = (new Query())->from(SubmissionRecord::TABLE);

        if (in_array($status, SubmissionRecord::STATUSES, true)) {
            $query->andWhere(['status' => $status]);
        }

        if ($engine !== '') {
            $query->andWhere(['engine' => $engine]);
        }

        if ($search !== '') {
            // An exact URL is matched on the hash, which is indexed; anything else falls back to a
            // scan, which is fine on a filtered ledger and unacceptable as the default path.
            $query->andWhere([
                'or',
                ['urlHash' => Plugin::getInstance()->urls->hash($search)],
                ['like', 'url', $search],
            ]);
        }

        return $query;
    }
}
