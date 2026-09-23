<?php

declare(strict_types=1);

namespace TrilbyMedia\GravDbKit\Schema;

use TrilbyMedia\GravDbKit\Database\Connection;
use TrilbyMedia\GravDbKit\Database\Dialect\Dialect;
use TrilbyMedia\GravDbKit\Database\KitTables;

/**
 * Migration steps for the tables the kit's own classes read and write: the
 * KV store, the job queue and the rate limiter. A plugin's first migration
 * uses them so its tables always match what the kit expects:
 *
 *     public function steps(Dialect $d): array
 *     {
 *         $t = KitTables::withPrefix('helpdesk');
 *
 *         return [
 *             'create_helpdesk_kv' => InfraTables::kv($d, $t),
 *             'create_helpdesk_jobs' => InfraTables::jobs($d, $t),
 *             'create_helpdesk_rate_limits' => InfraTables::rateLimits($d, $t),
 *         ];
 *     }
 *
 * The migrations and locks tables are not here: the migrator creates those
 * itself before it runs anything.
 *
 * Every step is idempotent like any other migration step, and the jobs step
 * also brings an older queue table forward: a table created before the dedupe
 * and cancellation columns existed (Forum Pro's `forum_jobs`, KahunaCart's
 * `kahunacart_jobs` before its migrations 0049 and 0077) gains the missing
 * columns and index in place, so the same step can be appended to an existing
 * plugin's migrations when it switches to the kit's queue.
 */
final class InfraTables
{
    private function __construct()
    {
    }

    /**
     * `kv_key` VARCHAR(64) PK, `kv_value` TEXT NULL, `updated_at` BIGINT.
     * Forum Pro's `forum_kv` exactly.
     *
     * @return \Closure(Connection): void
     */
    public static function kv(Dialect $d, KitTables $t): \Closure
    {
        return static function (Connection $c) use ($d, $t): void {
            if (!$d->tableExists($c->pdo(), $t->kv)) {
                $c->run("CREATE TABLE {$t->kv} (
                    kv_key VARCHAR(64) NOT NULL PRIMARY KEY,
                    kv_value TEXT NULL,
                    updated_at BIGINT NOT NULL
                ) {$d->tableOptions()}");
            }
        };
    }

    /**
     * The job queue table, as KahunaCart's `kahunacart_jobs` stands after its
     * migrations 0001, 0049 and 0077, without the store scope column:
     *
     *   id PK, type VARCHAR(64), payload_json TEXT NULL,
     *   run_after BIGINT DEFAULT 0, attempts INTEGER DEFAULT 0,
     *   max_attempts INTEGER DEFAULT 3, locked_at BIGINT NULL,
     *   locked_by VARCHAR(64) NULL, completed_at BIGINT NULL,
     *   last_error TEXT NULL, created_at BIGINT,
     *   cancel_requested_at BIGINT NULL, cancelled_at BIGINT NULL,
     *   dedupe_key VARCHAR(190) NULL
     *
     * Indexes `ix_{jobs}_pending` (completed_at, run_after), which the claim
     * query walks, and `ix_{jobs}_dedupe` (dedupe_key, completed_at), which
     * answers the "is this key already queued?" read enqueue() makes before
     * every keyed insert.
     *
     * @return \Closure(Connection): void
     */
    public static function jobs(Dialect $d, KitTables $t): \Closure
    {
        return static function (Connection $c) use ($d, $t): void {
            if (!$d->tableExists($c->pdo(), $t->jobs)) {
                $c->run("CREATE TABLE {$t->jobs} (
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
                    created_at BIGINT NOT NULL,
                    cancel_requested_at BIGINT NULL,
                    cancelled_at BIGINT NULL,
                    dedupe_key VARCHAR(190) NULL
                ) {$d->tableOptions()}");
            }

            // An older queue table: added one at a time, each guarded, so a run
            // cut short between two of them repeats cleanly.
            foreach ([
                'cancel_requested_at' => 'BIGINT NULL',
                'cancelled_at' => 'BIGINT NULL',
                'dedupe_key' => 'VARCHAR(190) NULL',
            ] as $column => $type) {
                if (!$d->columnExists($c->pdo(), $t->jobs, $column)) {
                    $c->run("ALTER TABLE {$t->jobs} ADD COLUMN {$column} {$type}");
                }
            }

            $d->createIndexIfMissing($c->pdo(), $t->jobs, self::indexName('ix_', $t->jobs, '_pending'), ['completed_at', 'run_after']);
            $d->createIndexIfMissing($c->pdo(), $t->jobs, self::indexName('ix_', $t->jobs, '_dedupe'), ['dedupe_key', 'completed_at']);
        };
    }

    /**
     * The rate limiter's table: one row per (bucket, key), rolled forward from
     * window to window.
     *
     *   id PK, bucket VARCHAR(64), rl_key VARCHAR(190), window_start BIGINT,
     *   hits INTEGER DEFAULT 0, UNIQUE (bucket, rl_key, window_start)
     *
     * plus `ix_{table}_window` (window_start) for the prune, the one read that
     * does not lead with the bucket.
     *
     * An existing table in an older layout is left alone: Forum Pro's has no
     * `bucket` column and KahunaCart's keeps bucket and key joined in one
     * `key` column. Their rows are counters that expire within minutes, so a
     * plugin switching over drops its old table in a step of its own and then
     * runs this one (see the README).
     *
     * @return \Closure(Connection): void
     */
    public static function rateLimits(Dialect $d, KitTables $t): \Closure
    {
        return static function (Connection $c) use ($d, $t): void {
            if (!$d->tableExists($c->pdo(), $t->rateLimits)) {
                $unique = self::indexName('uq_', $t->rateLimits, '');
                $c->run("CREATE TABLE {$t->rateLimits} (
                    id {$d->primaryKey()},
                    bucket VARCHAR(64) NOT NULL,
                    rl_key VARCHAR(190) NOT NULL,
                    window_start BIGINT NOT NULL,
                    hits INTEGER NOT NULL DEFAULT 0,
                    CONSTRAINT {$unique} UNIQUE (bucket, rl_key, window_start)
                ) {$d->tableOptions()}");
            }

            $d->createIndexIfMissing($c->pdo(), $t->rateLimits, self::indexName('ix_', $t->rateLimits, '_window'), ['window_start']);
        };
    }

    /**
     * Index and constraint names share one namespace per schema on
     * PostgreSQL and are capped at 63 characters there (64 on MySQL), so a
     * long table name is cut, with a short hash keeping two cut names apart.
     */
    private static function indexName(string $prefix, string $table, string $suffix): string
    {
        $name = $prefix . $table . $suffix;
        if (\strlen($name) <= 63) {
            return $name;
        }

        $hash = substr(hash('xxh128', $name), 0, 8);

        return substr($prefix . $table, 0, 63 - \strlen($suffix) - 9) . '_' . $hash . $suffix;
    }
}
