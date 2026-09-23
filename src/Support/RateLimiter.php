<?php

declare(strict_types=1);

namespace TrilbyMedia\GravDbKit\Support;

use TrilbyMedia\GravDbKit\Database\Connection;
use TrilbyMedia\GravDbKit\Database\KitTables;

/**
 * Fixed-window request counting, in the plugin's own database.
 *
 * Fixed window rather than a sliding log or a leaky bucket: one row per key
 * instead of one row per request, one statement per call, and an answer that a
 * `Retry-After` header can carry honestly. The known cost is the boundary — a
 * client can spend a full window's budget at the end of one window and again at
 * the start of the next — which is the right trade for what this protects
 * (public forms, sign-in links, license validation), where the point is to stop
 * a scripted sweep, not to shave the last burst off a well-behaved client.
 *
 * Windows are aligned to the epoch (`floor(now / window) * window`) rather than
 * started on first contact. That makes the turnover time a function of the
 * clock alone, so `retryAfter` is the same number for every client sharing a
 * window and a caller can reason about it without reading the row.
 *
 * The database is the shared store on purpose. APCu and the filesystem are both
 * per-process or per-node, and a limiter that resets when PHP-FPM recycles a
 * worker is not a limiter. Every write here is one statement, so two requests
 * arriving together cannot both read the same count and both decide they were
 * first.
 *
 * Every call names a **bucket** as well as a key. That is what lets one address
 * be counted separately for signing in, posting a form and asking for a fresh
 * link, without a table per route, and a caller cannot spend one route's budget
 * on another by picking a key that collides.
 *
 * The key is opaque: whatever the caller passes is what gets stored. A key
 * derived from anything personal or secret (an address, an email, a token)
 * should be hashed by the caller first; anonymize() is there for that. A key
 * longer than the column is stored as its SHA-256 rather than cut, so two long
 * keys sharing a prefix never count as one.
 *
 * The table is KitTables::$rateLimits, created by Schema\InfraTables::rateLimits().
 */
final class RateLimiter
{
    public const MAX_BUCKET_LENGTH = 64;
    public const MAX_KEY_LENGTH = 190;

    /**
     * Rows are pruned on roughly one call in this many. Cheap enough to be
     * invisible in aggregate, frequent enough that an abandoned key never
     * outlives its usefulness by more than a rounding error. There is no cron
     * to depend on and no growth to monitor.
     */
    public const PRUNE_ODDS = 50;

    /** How many windows of history a prune keeps. Anything older answers nothing. */
    private const PRUNE_WINDOWS = 2;

    private readonly KitTables $tables;
    private readonly Clock $clock;

    /**
     * @param int $pruneOdds one call in this many sweeps old windows; 0 turns
     *                       the opportunistic sweep off, for a caller that
     *                       runs prune() on its own schedule or a test that
     *                       is counting rows
     */
    public function __construct(
        private readonly Connection $db,
        ?KitTables $tables = null,
        private readonly int $pruneOdds = self::PRUNE_ODDS,
        ?Clock $clock = null,
    ) {
        $this->tables = $tables ?? new KitTables();
        $this->clock = $clock ?? new SystemClock();
    }

    /**
     * Count one call against $bucket/$key and say whether it may proceed.
     *
     * $limit is how many calls the window allows: `hit()` returns allowed for
     * calls 1..$limit and denied from $limit + 1 on. A denied call still counts
     * — the window is a period of time, not a budget that pauses when it runs
     * out — so hammering never postpones the turnover.
     *
     * A $limit of zero or less means **no limit**, and nothing is counted at
     * all: that is the off switch an administrator gets by clearing a field,
     * and it has to cost no queries or a site that turns a bucket off would
     * still pay for it on every request.
     *
     * $now overrides the clock for this one call.
     */
    public function hit(string $bucket, string $key, int $limit, int $windowSeconds, ?int $now = null): RateLimitResult
    {
        if ($limit <= 0) {
            return new RateLimitResult(true, \PHP_INT_MAX, 0);
        }

        $now ??= $this->clock->now();
        $windowSeconds = max(1, $windowSeconds);
        $bucket = self::bucket($bucket);
        $key = self::key($key);

        $windowStart = intdiv($now, $windowSeconds) * $windowSeconds;
        $hits = $this->count($bucket, $key, $windowStart);

        $this->maybePrune($windowStart, $windowSeconds);

        $allowed = $hits <= $limit;

        return new RateLimitResult(
            $allowed,
            max(0, $limit - $hits),
            $allowed ? 0 : $windowStart + $windowSeconds - $now
        );
    }

    /**
     * Drop every window that started before $before. Returns the row count.
     * Public because it is also the whole of the maintenance story: a plugin
     * that would rather sweep on a schedule than opportunistically can call it.
     */
    public function prune(int $before): int
    {
        return $this->db->delete($this->tables->rateLimits, 'window_start < ?', [$before]);
    }

    /** A stable, non-reversible key for a value that should not be stored as given (an IP, an email). */
    public static function anonymize(string $value): string
    {
        return hash('sha256', strtolower(trim($value)));
    }

    /**
     * This call's position in the current window, counting from 1.
     *
     * Every branch is a single statement whose WHERE clause names the state it
     * expects, so a racing request either wins that statement or falls through
     * to the next branch — no read-then-write, and nothing to lock.
     */
    private function count(string $bucket, string $key, int $windowStart): int
    {
        // The common case by far: the row is already in this window.
        if ($this->bump($bucket, $key, $windowStart) === 1) {
            return $this->readHits($bucket, $key, $windowStart);
        }

        // A row from an earlier window (or, after a clock correction, a later
        // one): roll it forward. Guarded on the window it is *not* in, so of two
        // racers exactly one resets and the other lands on the bump below.
        $rolled = $this->db->execute(
            "UPDATE {$this->tables->rateLimits} SET window_start = ?, hits = 1
             WHERE bucket = ? AND rl_key = ? AND window_start <> ?",
            [$windowStart, $bucket, $key, $windowStart]
        );
        if ($rolled === 1) {
            return 1;
        }

        // No row at all — the first call this key has ever made. Insert an
        // empty row if nobody has in the meantime, then count against whichever
        // row is there. An insert-if-absent rather than a caught duplicate, so
        // this is safe inside an open PostgreSQL transaction too. If even the
        // bump misses, the row moved again under us and one uncounted call is
        // not worth a retry loop.
        $this->db->upsert($this->tables->rateLimits, [
            'bucket' => $bucket,
            'rl_key' => $key,
            'window_start' => $windowStart,
            'hits' => 0,
        ], ['bucket', 'rl_key', 'window_start'], []);

        return $this->bump($bucket, $key, $windowStart) === 1
            ? $this->readHits($bucket, $key, $windowStart)
            : 1;
    }

    /** @return int rows updated: 1 when the key is already in this window, else 0 */
    private function bump(string $bucket, string $key, int $windowStart): int
    {
        return $this->db->execute(
            "UPDATE {$this->tables->rateLimits} SET hits = hits + 1
             WHERE bucket = ? AND rl_key = ? AND window_start = ?",
            [$bucket, $key, $windowStart]
        );
    }

    /**
     * The count after our increment. A concurrent call may have pushed it
     * higher by the time we read, which only ever makes the limiter stricter —
     * the safe direction, and never by more than the number of callers.
     */
    private function readHits(string $bucket, string $key, int $windowStart): int
    {
        $hits = $this->db->fetchValue(
            "SELECT hits FROM {$this->tables->rateLimits}
             WHERE bucket = ? AND rl_key = ? AND window_start = ?",
            [$bucket, $key, $windowStart]
        );

        return $hits === null ? 1 : max(1, (int)$hits);
    }

    private function maybePrune(int $windowStart, int $windowSeconds): void
    {
        if ($this->pruneOdds < 1 || random_int(1, $this->pruneOdds) !== 1) {
            return;
        }

        $this->prune($windowStart - self::PRUNE_WINDOWS * $windowSeconds);
    }

    private static function bucket(string $bucket): string
    {
        if ($bucket === '' || \strlen($bucket) > self::MAX_BUCKET_LENGTH) {
            throw new \InvalidArgumentException('Rate limit buckets are 1 to ' . self::MAX_BUCKET_LENGTH . " bytes: '{$bucket}'");
        }

        return $bucket;
    }

    private static function key(string $key): string
    {
        return \strlen($key) > self::MAX_KEY_LENGTH ? 'sha256:' . hash('sha256', $key) : $key;
    }
}
