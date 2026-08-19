<?php

declare(strict_types=1);

namespace justinholtweb\sanka\records;

use craft\db\ActiveRecord;

/**
 * One row of the ledger.
 *
 * Every path that could want a URL indexed — an entry save, a paste into the console, a bulk
 * resubmit, a sweep, a console command, a retry — writes one of these, and the dispatcher is the
 * only thing that reads them. That is the whole architecture: quota, deduplication, batching,
 * backoff and reporting are written once and behave identically regardless of cause.
 *
 * @property int $id
 * @property int|null $siteId
 * @property int|null $elementId
 * @property string $url
 * @property string $urlHash
 * @property string $engine
 * @property string $type
 * @property string $reason
 * @property string $status
 * @property int $attempts
 * @property string|null $nextAttempt
 * @property int|null $statusCode
 * @property string|null $message
 * @property string|null $dateSent
 * @property string $dateCreated
 * @property string $dateUpdated
 * @property string $uid
 */
class SubmissionRecord extends ActiveRecord
{
    public const TABLE = '{{%sanka_submissions}}';

    /** Waiting for the dispatcher. Also the state a retry goes back to. */
    public const STATUS_PENDING = 'pending';

    /** The engine accepted it. */
    public const STATUS_SENT = 'sent';

    /** Attempts exhausted, or an answer no retry could improve. */
    public const STATUS_FAILED = 'failed';

    /**
     * Deliberately not sent: cooldown, quota, dry run, or a rule that said no.
     *
     * A first-class outcome rather than a silent drop — a URL that did not go anywhere always says
     * why it did not go anywhere.
     */
    public const STATUS_SKIPPED = 'skipped';

    public const STATUSES = [self::STATUS_PENDING, self::STATUS_SENT, self::STATUS_FAILED, self::STATUS_SKIPPED];

    public const TYPE_UPDATED = 'update';
    public const TYPE_DELETED = 'delete';

    public const TYPES = [self::TYPE_UPDATED, self::TYPE_DELETED];

    public static function tableName(): string
    {
        return self::TABLE;
    }
}
