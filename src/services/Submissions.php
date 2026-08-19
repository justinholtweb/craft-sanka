<?php

declare(strict_types=1);

namespace justinholtweb\sanka\services;

use Craft;
use craft\base\Component;
use craft\base\ElementInterface;
use craft\db\Query;
use craft\helpers\Db;
use DateTime;
use DateTimeZone;
use justinholtweb\sanka\models\SubmissionResult;
use justinholtweb\sanka\Plugin;
use justinholtweb\sanka\records\SubmissionRecord;

/**
 * The ledger.
 *
 * Everything that could want a URL indexed writes one row here and stops. The dispatcher is the
 * only thing that reads them. That separation is the reason quota, deduplication, cooldown, backoff
 * and the audit trail exist once rather than once per caller — an entry save, a paste into the
 * console, a sweep and a retry are indistinguishable by the time they reach the engine, which is
 * exactly what makes the behaviour predictable.
 *
 * The other rule this service enforces: **a URL that does not get submitted says why.** `skipped` is
 * a recorded outcome with a message, not a silent return. Almost every support question about a
 * tool like this is “why didn’t it send my page”, and the answer should be on the screen.
 */
class Submissions extends Component
{
    public const REASON_SAVE = 'entry saved';
    public const REASON_DELETE = 'entry deleted';
    public const REASON_CONSOLE = 'console';
    public const REASON_BULK = 'bulk resubmit';
    public const REASON_SWEEP = 'sweep';
    public const REASON_COMMAND = 'command';
    public const REASON_RETRY = 'retry';

    /**
     * Queue one URL for one engine.
     *
     * Returns the row that resulted, whatever its status — including the `skipped` rows, because
     * “Sanka decided not to send this and here is why” is information the caller usually wants to
     * show. Returns null only when an identical submission is already waiting, since queueing the
     * same URL twice would cost twice the quota to achieve exactly nothing.
     */
    public function queue(
        string $url,
        string $engine,
        string $type = SubmissionRecord::TYPE_UPDATED,
        string $reason = self::REASON_CONSOLE,
        ?int $elementId = null,
        ?int $siteId = null,
    ): ?SubmissionRecord {
        $url = trim($url);
        $urls = Plugin::getInstance()->urls;
        $hash = $urls->hash($url);
        $siteId ??= $urls->siteIdForUrl($url);

        $problem = $urls->problem($url);

        if ($problem !== null) {
            return $this->write($url, $hash, $engine, $type, $reason, $elementId, $siteId, [
                'status' => SubmissionRecord::STATUS_SKIPPED,
                'message' => $problem,
            ]);
        }

        if ($this->hasPending($hash, $engine, $type)) {
            return null;
        }

        $cooldown = $this->cooldownProblem($hash, $engine, $type);

        if ($cooldown !== null) {
            return $this->write($url, $hash, $engine, $type, $reason, $elementId, $siteId, [
                'status' => SubmissionRecord::STATUS_SKIPPED,
                'message' => $cooldown,
            ]);
        }

        return $this->write($url, $hash, $engine, $type, $reason, $elementId, $siteId, [
            'status' => SubmissionRecord::STATUS_PENDING,
        ]);
    }

    /**
     * Queue one URL for several engines at once.
     *
     * @param list<string>|null $engines null means every usable engine
     * @return list<SubmissionRecord>
     */
    public function queueUrl(
        string $url,
        ?array $engines = null,
        string $type = SubmissionRecord::TYPE_UPDATED,
        string $reason = self::REASON_CONSOLE,
        ?int $elementId = null,
        ?int $siteId = null,
    ): array {
        $registry = Plugin::getInstance()->engines;
        $usable = $registry->getUsableHandles();
        $engines = $engines === null ? $usable : array_values(array_intersect($engines, $usable));

        $rows = [];

        foreach ($engines as $handle) {
            $engine = $registry->get($handle);

            if ($engine === null) {
                continue;
            }

            if (!$engine->supportsType($type)) {
                continue;
            }

            $row = $this->queue($url, $handle, $type, $reason, $elementId, $siteId);

            if ($row !== null) {
                $rows[] = $row;
            }
        }

        return $rows;
    }

    /**
     * Queue an element's URL, for every engine a matching rule names.
     *
     * @return list<SubmissionRecord>
     */
    public function queueElement(
        ElementInterface $element,
        string $type = SubmissionRecord::TYPE_UPDATED,
        string $reason = self::REASON_SAVE,
        ?array $engines = null,
    ): array {
        $url = Plugin::getInstance()->urls->forElement($element);

        if ($url === null) {
            return [];
        }

        return $this->queueUrl($url, $engines, $type, $reason, $element->id, $element->siteId);
    }

    /**
     * Rows waiting for a given engine, oldest first, honouring the backoff.
     *
     * @return list<SubmissionRecord>
     */
    public function pending(string $engine, ?string $type = null, int $limit = 100): array
    {
        $now = Db::prepareDateForDb(new DateTime());

        $query = SubmissionRecord::find()
            ->where(['status' => SubmissionRecord::STATUS_PENDING, 'engine' => $engine])
            ->andWhere(['or', ['nextAttempt' => null], ['<=', 'nextAttempt', $now]])
            ->orderBy(['dateCreated' => SORT_ASC, 'id' => SORT_ASC])
            ->limit($limit);

        if ($type !== null) {
            $query->andWhere(['type' => $type]);
        }

        /** @var list<SubmissionRecord> */
        return $query->all();
    }

    /**
     * The notification types that currently have work waiting for an engine.
     *
     * Asked separately because an engine call carries exactly one type, so a mixed queue has to be
     * drained as one call per type rather than one call per drain.
     *
     * @return list<string>
     */
    public function pendingTypes(string $engine): array
    {
        $now = Db::prepareDateForDb(new DateTime());

        $types = (new Query())
            ->select(['type'])
            ->distinct()
            ->from(SubmissionRecord::TABLE)
            ->where(['status' => SubmissionRecord::STATUS_PENDING, 'engine' => $engine])
            ->andWhere(['or', ['nextAttempt' => null], ['<=', 'nextAttempt', $now]])
            ->column();

        return array_values(array_map('strval', $types));
    }

    public function pendingCount(?string $engine = null): int
    {
        $query = (new Query())
            ->from(SubmissionRecord::TABLE)
            ->where(['status' => SubmissionRecord::STATUS_PENDING]);

        if ($engine !== null) {
            $query->andWhere(['engine' => $engine]);
        }

        return (int)$query->count();
    }

    /**
     * Record what an engine said about a row.
     *
     * A retryable failure goes back to `pending` with a doubling wait rather than to `failed`, until
     * the attempt budget runs out — at which point it stops for good and says how many times it
     * tried, because a row that silently retries forever is a row nobody ever looks at.
     */
    public function record(SubmissionRecord $row, SubmissionResult $result): void
    {
        $settings = Plugin::getInstance()->getSettings();

        $row->attempts = (int)$row->attempts + 1;
        $row->statusCode = $result->statusCode !== 0 ? $result->statusCode : null;
        $row->message = $result->message !== '' ? $result->message : null;

        if ($result->isSent()) {
            $row->status = SubmissionRecord::STATUS_SENT;
            $row->dateSent = Db::prepareDateForDb(new DateTime());
            $row->nextAttempt = null;
            $row->save(false);

            return;
        }

        if ($result->status === SubmissionRecord::STATUS_SKIPPED) {
            $row->status = SubmissionRecord::STATUS_SKIPPED;
            $row->nextAttempt = null;
            $row->save(false);

            return;
        }

        if ($result->retryable && $row->attempts < $settings->maxAttempts) {
            $wait = $settings->retryBackoff * (2 ** ($row->attempts - 1));

            $row->status = SubmissionRecord::STATUS_PENDING;
            $row->nextAttempt = Db::prepareDateForDb((new DateTime())->modify('+' . (int)$wait . ' seconds'));
            $row->save(false);

            return;
        }

        $row->status = SubmissionRecord::STATUS_FAILED;
        $row->nextAttempt = null;

        if ($result->retryable) {
            $row->message = Craft::t('sanka', '{message} Gave up after {attempts} attempts.', [
                'message' => (string)$row->message,
                'attempts' => $row->attempts,
            ]);
        }

        $row->save(false);
    }

    /**
     * Put a finished row back in the queue.
     *
     * The attempt counter is reset, because a human choosing to retry is a new decision rather than
     * a continuation of the automatic one that gave up.
     */
    public function requeue(SubmissionRecord $row): void
    {
        $row->status = SubmissionRecord::STATUS_PENDING;
        $row->attempts = 0;
        $row->nextAttempt = null;
        $row->statusCode = null;
        $row->message = null;
        $row->dateSent = null;
        $row->save(false);
    }

    /**
     * Everything that ever happened to one URL, newest first.
     *
     * @return list<SubmissionRecord>
     */
    public function history(string $url, int $limit = 50): array
    {
        /** @var list<SubmissionRecord> */
        return SubmissionRecord::find()
            ->where(['urlHash' => Plugin::getInstance()->urls->hash($url)])
            ->orderBy(['dateCreated' => SORT_DESC])
            ->limit($limit)
            ->all();
    }

    /**
     * Counts by status for one engine over a window, for the overview screen.
     *
     * @return array<string, int>
     */
    public function summary(?string $engine = null, int $days = 30): array
    {
        $cutoff = Db::prepareDateForDb((new DateTime())->modify("-{$days} days"));

        $query = (new Query())
            ->select(['status', 'total' => 'COUNT(*)'])
            ->from(SubmissionRecord::TABLE)
            ->where(['>=', 'dateCreated', $cutoff])
            ->groupBy(['status']);

        if ($engine !== null) {
            $query->andWhere(['engine' => $engine]);
        }

        $counts = array_fill_keys(SubmissionRecord::STATUSES, 0);

        foreach ($query->all() as $row) {
            $counts[(string)$row['status']] = (int)$row['total'];
        }

        return $counts;
    }

    /**
     * Drop rows past the retention window, keeping anything still pending.
     */
    public function prune(int $days): int
    {
        $cutoff = Db::prepareDateForDb((new DateTime())->modify("-{$days} days"));

        return Craft::$app->getDb()->createCommand()
            ->delete(SubmissionRecord::TABLE, [
                'and',
                ['<', 'dateCreated', $cutoff],
                ['not', ['status' => SubmissionRecord::STATUS_PENDING]],
            ])
            ->execute();
    }

    // ----------------------------------------------------------------- private

    private function hasPending(string $hash, string $engine, string $type): bool
    {
        return SubmissionRecord::find()
            ->where([
                'urlHash' => $hash,
                'engine' => $engine,
                'type' => $type,
                'status' => SubmissionRecord::STATUS_PENDING,
            ])
            ->exists();
    }

    /**
     * Whether this URL went to this engine too recently to be worth sending again.
     *
     * This is what stops a save storm costing quota: an author who saves twenty times while editing
     * spends one submission, not twenty. It is keyed on url+engine+type rather than on the element,
     * so two entries sharing a URL — a home page and its section index — also share the cooldown.
     */
    private function cooldownProblem(string $hash, string $engine, string $type): ?string
    {
        $cooldown = Plugin::getInstance()->getSettings()->cooldown;

        if ($cooldown <= 0) {
            return null;
        }

        $since = Db::prepareDateForDb((new DateTime())->modify("-{$cooldown} seconds"));

        $last = (new Query())
            ->select(['dateSent'])
            ->from(SubmissionRecord::TABLE)
            ->where([
                'urlHash' => $hash,
                'engine' => $engine,
                'type' => $type,
                'status' => SubmissionRecord::STATUS_SENT,
            ])
            ->andWhere(['>=', 'dateSent', $since])
            ->orderBy(['dateSent' => SORT_DESC])
            ->scalar();

        if ($last === false || $last === null) {
            return null;
        }

        // The column holds UTC. Reading it back without saying so would show a time the operator
        // does not recognise, off by their whole offset — so it is converted explicitly rather than
        // handed to anything that would guess the zone.
        $sentAt = new DateTime((string)$last, new DateTimeZone('UTC'));
        $sentAt->setTimezone(new DateTimeZone(Craft::$app->getTimeZone()));

        return Craft::t('sanka', 'Already submitted at {time}. The cooldown is {seconds} seconds, so this save cost no quota.', [
            'time' => $sentAt->format('Y-m-d H:i:s'),
            'seconds' => $cooldown,
        ]);
    }

    /**
     * @param array<string, mixed> $attributes
     */
    private function write(
        string $url,
        string $hash,
        string $engine,
        string $type,
        string $reason,
        ?int $elementId,
        ?int $siteId,
        array $attributes,
    ): SubmissionRecord {
        // `EVENT_AFTER_DELETE_ELEMENT` fires *after* the row is gone on a hard delete, so keeping
        // the id would violate the foreign key and take the deletion down with it. A soft delete
        // leaves the row in place and keeps its link. This is the only place rows are written, so
        // nothing can get past it.
        if ($elementId !== null && $type === SubmissionRecord::TYPE_DELETED) {
            $stillThere = (new Query())
                ->from(\craft\db\Table::ELEMENTS)
                ->where(['id' => $elementId])
                ->exists();

            if (!$stillThere) {
                $elementId = null;
            }
        }

        $row = new SubmissionRecord();
        $row->url = $url;
        $row->urlHash = $hash;
        $row->engine = $engine;
        $row->type = $type;
        $row->reason = mb_substr($reason, 0, 64);
        $row->elementId = $elementId;
        $row->siteId = $siteId;
        $row->status = SubmissionRecord::STATUS_PENDING;
        $row->attempts = 0;

        foreach ($attributes as $name => $value) {
            $row->{$name} = $value;
        }

        $row->save(false);

        return $row;
    }
}
