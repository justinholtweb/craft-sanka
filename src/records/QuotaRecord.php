<?php

declare(strict_types=1);

namespace justinholtweb\sanka\records;

use craft\db\ActiveRecord;

/**
 * How much of an engine's daily allowance has been spent.
 *
 * One row per engine per day. The date is the engine's own idea of a day — Google's quota resets at
 * midnight Pacific, not at the server's midnight — which is why the date is stored as a string the
 * engine computes rather than derived from `dateCreated`.
 *
 * @property int $id
 * @property string $engine
 * @property string $quotaDate
 * @property int $used
 * @property string $dateCreated
 * @property string $dateUpdated
 * @property string $uid
 */
class QuotaRecord extends ActiveRecord
{
    public const TABLE = '{{%sanka_quota}}';

    public static function tableName(): string
    {
        return self::TABLE;
    }
}
