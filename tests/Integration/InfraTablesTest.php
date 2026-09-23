<?php

declare(strict_types=1);

namespace TrilbyMedia\GravDbKit\Tests\Integration;

use PHPUnit\Framework\TestCase;
use TrilbyMedia\GravDbKit\Database\Connection;
use TrilbyMedia\GravDbKit\Database\KitTables;
use TrilbyMedia\GravDbKit\Schema\InfraTables;
use TrilbyMedia\GravDbKit\Tests\Support\TestEngine;

final class InfraTablesTest extends TestCase
{
    private Connection $db;

    protected function setUp(): void
    {
        $this->db = TestEngine::fresh();
    }

    public function testEachStepCreatesItsTableAndRepeatsCleanly(): void
    {
        $d = $this->db->dialect();
        $t = KitTables::withPrefix('kit_i');

        foreach ([InfraTables::kv($d, $t), InfraTables::jobs($d, $t), InfraTables::rateLimits($d, $t)] as $step) {
            $step($this->db);
            $step($this->db);
        }

        foreach (['kit_i_kv', 'kit_i_jobs', 'kit_i_rate_limits'] as $table) {
            self::assertTrue($d->tableExists($this->db->pdo(), $table), $table);
        }
    }

    /**
     * The columns W0.2's JobQueue reads and writes, as KahunaCart's queue
     * expects them after its migrations 0001, 0049 and 0077.
     */
    public function testTheJobsTableHasEveryColumnTheQueueUses(): void
    {
        $d = $this->db->dialect();
        $t = new KitTables();
        InfraTables::jobs($d, $t)($this->db);

        foreach ([
            'id', 'type', 'payload_json', 'dedupe_key', 'run_after', 'attempts', 'max_attempts',
            'locked_at', 'locked_by', 'completed_at', 'cancel_requested_at', 'cancelled_at',
            'last_error', 'created_at',
        ] as $column) {
            self::assertTrue($d->columnExists($this->db->pdo(), 'kit_jobs', $column), "kit_jobs.{$column}");
        }

        $id = $this->db->insert('kit_jobs', ['type' => 'mail.send', 'payload_json' => '{}', 'created_at' => 1]);
        $row = $this->db->fetchRow('SELECT * FROM kit_jobs WHERE id = ?', [$id]);
        self::assertSame(0, (int)$row['run_after']);
        self::assertSame(0, (int)$row['attempts']);
        self::assertSame(3, (int)$row['max_attempts']);
        self::assertNull($row['dedupe_key']);
        self::assertNull($row['completed_at']);

        // Both indexes exist under the names the step promises (a repeat is a no-op).
        $d->createIndexIfMissing($this->db->pdo(), 'kit_jobs', 'ix_kit_jobs_pending', ['completed_at', 'run_after']);
        $d->createIndexIfMissing($this->db->pdo(), 'kit_jobs', 'ix_kit_jobs_dedupe', ['dedupe_key', 'completed_at']);
        self::assertTrue($this->indexExists('kit_jobs', 'ix_kit_jobs_pending'));
        self::assertTrue($this->indexExists('kit_jobs', 'ix_kit_jobs_dedupe'));
    }

    /**
     * A queue table from before the dedupe and cancellation columns (Forum
     * Pro's forum_jobs today) is brought forward in place, rows and all.
     */
    public function testAnOlderJobsTableGainsTheMissingColumns(): void
    {
        $d = $this->db->dialect();
        $this->db->run("CREATE TABLE kit_old_jobs (
            id {$d->primaryKey()},
            type VARCHAR(64) NOT NULL,
            payload_json TEXT NULL,
            run_after BIGINT NOT NULL DEFAULT 0,
            attempts INTEGER NOT NULL DEFAULT 0,
            max_attempts INTEGER NOT NULL DEFAULT 3,
            locked_at BIGINT NULL,
            locked_by VARCHAR(64) NULL,
            completed_at BIGINT NULL,
            last_error TEXT NULL,
            created_at BIGINT NOT NULL
        ) {$d->tableOptions()}");
        $d->createIndexIfMissing($this->db->pdo(), 'kit_old_jobs', 'ix_kit_old_jobs_pending', ['completed_at', 'run_after']);
        $id = $this->db->insert('kit_old_jobs', ['type' => 'noop', 'created_at' => 5]);

        $step = InfraTables::jobs($d, new KitTables(jobs: 'kit_old_jobs'));
        $step($this->db);
        $step($this->db);

        foreach (['cancel_requested_at', 'cancelled_at', 'dedupe_key'] as $column) {
            self::assertTrue($d->columnExists($this->db->pdo(), 'kit_old_jobs', $column), $column);
        }
        self::assertTrue($this->indexExists('kit_old_jobs', 'ix_kit_old_jobs_dedupe'));
        self::assertSame('noop', $this->db->fetchValue('SELECT type FROM kit_old_jobs WHERE id = ?', [$id]));
    }

    public function testTheRateLimitTableIsUniqueOnBucketKeyAndWindow(): void
    {
        $d = $this->db->dialect();
        InfraTables::rateLimits($d, new KitTables())($this->db);

        $row = ['bucket' => 'login', 'rl_key' => 'ip', 'window_start' => 60, 'hits' => 1];
        $this->db->insert('kit_rate_limits', $row);
        $this->db->insert('kit_rate_limits', ['bucket' => 'post'] + $row);
        $this->db->insert('kit_rate_limits', ['window_start' => 120] + $row);

        try {
            $this->db->insert('kit_rate_limits', $row);
            self::fail('the same bucket, key and window twice');
        } catch (\PDOException $e) {
            self::assertTrue($this->db->isUniqueViolation($e));
        }
    }

    public function testKvRowsAreKeyedByKvKey(): void
    {
        $d = $this->db->dialect();
        InfraTables::kv($d, new KitTables())($this->db);

        $this->db->upsert('kit_kv', ['kv_key' => 'a', 'kv_value' => '1', 'updated_at' => 1], ['kv_key'], ['kv_value']);
        $this->db->upsert('kit_kv', ['kv_key' => 'a', 'kv_value' => '2', 'updated_at' => 1], ['kv_key'], ['kv_value']);

        self::assertSame('2', $this->db->fetchValue("SELECT kv_value FROM kit_kv WHERE kv_key = 'a'"));
    }

    /** Index names are cut to what PostgreSQL allows, and two cut names stay apart. */
    public function testALongTableNameStillGetsValidIndexNames(): void
    {
        $d = $this->db->dialect();
        $long = 'kit_' . str_repeat('a', 54);
        InfraTables::jobs($d, new KitTables(jobs: $long))($this->db);
        InfraTables::jobs($d, new KitTables(jobs: $long))($this->db);

        self::assertTrue($d->tableExists($this->db->pdo(), $long));
    }

    private function indexExists(string $table, string $index): bool
    {
        $sql = match ($this->db->dialect()->name()) {
            'mysql' => 'SELECT 1 FROM information_schema.statistics WHERE table_schema = DATABASE() AND table_name = ? AND index_name = ?',
            'pgsql' => 'SELECT 1 FROM pg_indexes WHERE schemaname = current_schema() AND tablename = ? AND indexname = ?',
            default => "SELECT 1 FROM sqlite_master WHERE type = 'index' AND tbl_name = ? AND name = ?",
        };

        return $this->db->fetchValue($sql, [$table, $index]) !== null;
    }
}
