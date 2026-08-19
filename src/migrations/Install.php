<?php

declare(strict_types=1);

namespace justinholtweb\sanka\migrations;

use craft\db\Migration;
use craft\db\Table;
use justinholtweb\sanka\records\CrawlRecord;
use justinholtweb\sanka\records\QuotaRecord;
use justinholtweb\sanka\records\SubmissionRecord;

class Install extends Migration
{
    public function safeUp(): bool
    {
        // MySQL commits implicitly on DDL, so a migration that fails half way leaves its finished
        // tables behind and Yii's rollback cannot take them back. Without this guard the retry —
        // which is what anybody does next — fails on the first table it already made.
        $this->dropTables();

        $this->createTables();
        $this->createIndexes();
        $this->addForeignKeys();

        return true;
    }

    public function safeDown(): bool
    {
        $this->dropTables();

        return true;
    }

    private function dropTables(): void
    {
        // Foreign keys first, so the order is the reverse of creation.
        $this->dropTableIfExists(CrawlRecord::TABLE);
        $this->dropTableIfExists(QuotaRecord::TABLE);
        $this->dropTableIfExists(SubmissionRecord::TABLE);
    }

    private function createTables(): void
    {
        $this->createTable(SubmissionRecord::TABLE, [
            'id' => $this->primaryKey(),
            'siteId' => $this->integer(),

            // Nullable and SET NULL on delete: a submission outlives the entry that caused it, and
            // the most interesting row in the whole ledger is often the URL_DELETED for something
            // that no longer exists.
            'elementId' => $this->integer(),

            'url' => $this->text()->notNull(),

            // URLs are longer than MySQL will index and duplicate constantly. Every lookup that
            // matters — cooldown, dedupe, “show me this URL's history” — is an equality test, so
            // the hash is the indexed column and `url` is never searched directly.
            'urlHash' => $this->char(40)->notNull(),

            'engine' => $this->string(32)->notNull(),
            'type' => $this->string(16)->notNull(),

            // Free text, written by whatever queued the row: “entry saved”, “console”, “sweep”,
            // “bulk resubmit”. It is the answer to “why did this cost me quota”.
            'reason' => $this->string(64)->notNull()->defaultValue(''),

            'status' => $this->string(16)->notNull()->defaultValue(SubmissionRecord::STATUS_PENDING),
            'attempts' => $this->integer()->notNull()->defaultValue(0),

            // Null means “eligible now”. Set to a future time by the backoff.
            'nextAttempt' => $this->dateTime(),

            'statusCode' => $this->integer(),
            'message' => $this->text(),
            'dateSent' => $this->dateTime(),

            'dateCreated' => $this->dateTime()->notNull(),
            'dateUpdated' => $this->dateTime()->notNull(),
            'uid' => $this->uid(),
        ]);

        $this->createTable(QuotaRecord::TABLE, [
            'id' => $this->primaryKey(),
            'engine' => $this->string(32)->notNull(),

            // A string, not a date: this is the *engine's* day. Google's quota rolls over at
            // midnight Pacific whatever the server thinks the date is.
            'quotaDate' => $this->char(10)->notNull(),

            'used' => $this->integer()->notNull()->defaultValue(0),
            'dateCreated' => $this->dateTime()->notNull(),
            'dateUpdated' => $this->dateTime()->notNull(),
            'uid' => $this->uid(),
        ]);

        $this->createTable(CrawlRecord::TABLE, [
            'id' => $this->primaryKey(),
            'siteId' => $this->integer(),
            'agent' => $this->string(64)->notNull(),
            'userAgent' => $this->string(512)->notNull()->defaultValue(''),
            'url' => $this->text()->notNull(),
            'urlHash' => $this->char(40)->notNull(),
            'statusCode' => $this->integer(),
            'ip' => $this->string(45),

            // Null until forward-confirmed reverse DNS has run. Until then the row only says
            // something *claimed* to be this agent.
            'verified' => $this->boolean(),

            'dateCreated' => $this->dateTime()->notNull(),
            'dateUpdated' => $this->dateTime()->notNull(),
            'uid' => $this->uid(),
        ]);
    }

    private function createIndexes(): void
    {
        // The dispatcher's own query: pending rows for one engine, oldest first.
        $this->createIndex(null, SubmissionRecord::TABLE, ['status', 'engine', 'nextAttempt']);

        // Cooldown and dedupe.
        $this->createIndex(null, SubmissionRecord::TABLE, ['urlHash', 'engine', 'type']);

        $this->createIndex(null, SubmissionRecord::TABLE, ['elementId']);
        $this->createIndex(null, SubmissionRecord::TABLE, ['siteId']);
        $this->createIndex(null, SubmissionRecord::TABLE, ['dateCreated']);

        // Unique, which is what makes the update-then-insert in the Quota service safe: two requests
        // racing to create the same row means one of them gets an integrity error rather than two
        // rows that each hold half the count.
        $this->createIndex(null, QuotaRecord::TABLE, ['engine', 'quotaDate'], true);

        $this->createIndex(null, CrawlRecord::TABLE, ['agent', 'dateCreated']);
        $this->createIndex(null, CrawlRecord::TABLE, ['urlHash']);
        $this->createIndex(null, CrawlRecord::TABLE, ['dateCreated']);
    }

    private function addForeignKeys(): void
    {
        $this->addForeignKey(null, SubmissionRecord::TABLE, ['siteId'], Table::SITES, ['id'], 'CASCADE', null);
        $this->addForeignKey(null, SubmissionRecord::TABLE, ['elementId'], Table::ELEMENTS, ['id'], 'SET NULL', null);
        $this->addForeignKey(null, CrawlRecord::TABLE, ['siteId'], Table::SITES, ['id'], 'CASCADE', null);
    }
}
