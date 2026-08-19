<?php

declare(strict_types=1);

namespace justinholtweb\sanka\records;

use craft\db\ActiveRecord;

/**
 * One request from a recognised AI agent.
 *
 * `verified` is null until something checks it, because forward-confirmed reverse DNS costs two DNS
 * round trips and cannot be done on the request being logged. A console command backfills it. Until
 * then a row means “something claimed to be this agent”, which is a materially weaker statement and
 * the control panel says so.
 *
 * @property int $id
 * @property int|null $siteId
 * @property string $agent
 * @property string $userAgent
 * @property string $url
 * @property string $urlHash
 * @property int|null $statusCode
 * @property string|null $ip
 * @property bool|null $verified
 * @property string $dateCreated
 * @property string $dateUpdated
 * @property string $uid
 */
class CrawlRecord extends ActiveRecord
{
    public const TABLE = '{{%sanka_crawls}}';

    public static function tableName(): string
    {
        return self::TABLE;
    }
}
