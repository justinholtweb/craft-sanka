<?php

declare(strict_types=1);

namespace justinholtweb\sanka\services;

use Craft;
use craft\base\Component;
use craft\helpers\Db;
use DateTime;
use justinholtweb\sanka\engines\EngineInterface;
use justinholtweb\sanka\records\QuotaRecord;
use yii\db\Expression;
use yii\db\IntegrityException;

/**
 * How much of each engine's daily allowance has been spent.
 *
 * The point of this service is a single sentence: **quota is checked before the call, not after the
 * failure.** Google gives 200 publishes a day by default. Discovering it is gone by receiving a 429
 * — after spending a request to find out — is the failure mode that makes indexing tools useless,
 * because the requests that get refused are the ones queued after the ones that did not matter.
 */
class Quota extends Component
{
    /**
     * How many more URLs this engine will take today. Null means it publishes no daily limit.
     */
    public function remaining(EngineInterface $engine): ?int
    {
        $limit = $engine->dailyQuota();

        if ($limit === null) {
            return null;
        }

        return max(0, $limit - $this->used($engine->handle(), $engine->quotaDate()));
    }

    public function used(string $engine, string $date): int
    {
        $used = (new \craft\db\Query())
            ->select(['used'])
            ->from(QuotaRecord::TABLE)
            ->where(['engine' => $engine, 'quotaDate' => $date])
            ->scalar();

        // `count()` and friends come back from PDO as strings; so does this.
        return $used === false || $used === null ? 0 : (int)$used;
    }

    /**
     * Record that an engine's allowance was spent.
     *
     * Update-then-insert rather than an upsert helper: the update has to be an *increment*, which no
     * upsert helper expresses, and two requests finishing at the same moment must not lose one of
     * the two counts. Incrementing by a positive amount always changes the value, so MySQL's habit
     * of reporting zero affected rows for a no-op update cannot be mistaken for “no such row”.
     */
    public function consume(string $engine, string $date, int $amount = 1): void
    {
        if ($amount <= 0) {
            return;
        }

        $db = Craft::$app->getDb();
        $now = Db::prepareDateForDb(new DateTime());
        $condition = ['engine' => $engine, 'quotaDate' => $date];

        $affected = $db->createCommand()
            ->update(QuotaRecord::TABLE, [
                'used' => new Expression('[[used]] + :amount', [':amount' => $amount]),
                'dateUpdated' => $now,
            ], $condition)
            ->execute();

        if ($affected > 0) {
            return;
        }

        try {
            $db->createCommand()
                ->insert(QuotaRecord::TABLE, [
                    'engine' => $engine,
                    'quotaDate' => $date,
                    'used' => $amount,
                    'dateCreated' => $now,
                    'dateUpdated' => $now,
                    'uid' => \craft\helpers\StringHelper::UUID(),
                ])
                ->execute();
        } catch (IntegrityException) {
            // Another request created the row between the update and the insert. The unique index
            // on (engine, quotaDate) is what makes that safe to discover this way.
            $db->createCommand()
                ->update(QuotaRecord::TABLE, [
                    'used' => new Expression('[[used]] + :amount', [':amount' => $amount]),
                    'dateUpdated' => $now,
                ], $condition)
                ->execute();
        }
    }

    /**
     * Usage over the last N days, newest first, for the overview screen.
     *
     * @return list<array{engine: string, quotaDate: string, used: int}>
     */
    public function recent(string $engine, int $days = 14): array
    {
        $rows = (new \craft\db\Query())
            ->select(['engine', 'quotaDate', 'used'])
            ->from(QuotaRecord::TABLE)
            ->where(['engine' => $engine])
            ->orderBy(['quotaDate' => SORT_DESC])
            ->limit($days)
            ->all();

        return array_map(static fn(array $row): array => [
            'engine' => (string)$row['engine'],
            'quotaDate' => (string)$row['quotaDate'],
            'used' => (int)$row['used'],
        ], $rows);
    }

    /**
     * Forget an engine's history. Only reachable from the console, and only ever used when somebody
     * has just been granted a larger quota and wants the gauge to stop lying to them.
     */
    public function reset(string $engine, ?string $date = null): int
    {
        $condition = ['engine' => $engine];

        if ($date !== null) {
            $condition['quotaDate'] = $date;
        }

        return Craft::$app->getDb()->createCommand()->delete(QuotaRecord::TABLE, $condition)->execute();
    }

    /**
     * Drop rows older than the retention window. Called from garbage collection.
     */
    public function prune(int $days): int
    {
        $cutoff = (new DateTime())->modify("-{$days} days")->format('Y-m-d');

        return Craft::$app->getDb()->createCommand()
            ->delete(QuotaRecord::TABLE, ['<', 'quotaDate', $cutoff])
            ->execute();
    }
}
