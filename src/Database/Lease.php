<?php

declare(strict_types=1);

namespace TrilbyMedia\GravDbKit\Database;

use TrilbyMedia\GravDbKit\Support\Clock;
use TrilbyMedia\GravDbKit\Support\SystemClock;

/**
 * A named, expiring lock held as one row in the locks table.
 *
 * KahunaCart wrote this dance out three times (the migrator's schema lock, the
 * catalog index drain and the per-order refund lock) and Forum Pro once more;
 * this is the one copy. Each caller decides what failing to get the lease
 * means: the migrator throws, a queue drain shrugs and lets the holder carry
 * on, a refund answers "try again in a moment". So acquire() only ever says
 * yes or no, and run() is the throwing convenience for the callers that want
 * that.
 *
 * The row expires on its own, so a process that died holding it locks nothing
 * out for longer than its TTL: an expired row is fair game and is taken over
 * in place.
 *
 * Every acquisition gets an owner string of its own: the configured owner
 * (host and pid by default) plus a random tail. Host and pid alone are not
 * unique enough: two leases inside one request (a split refund, or a test)
 * share them, and the second release would then delete the first's row. The
 * owner is remembered per name, so release() deletes only the row this
 * object actually took and never one another process has since taken over.
 *
 * No step here relies on catching a failed INSERT. A takeover is a guarded
 * UPDATE, a fresh row is an insert-if-absent through the dialect's upsert, and
 * whether we won is read back from the row. That keeps a lease usable inside
 * an open PostgreSQL transaction, where a failed statement would poison
 * everything after it, and it means a deadlock or any other real error
 * reaches the caller instead of reading as "somebody else holds it".
 */
final class Lease
{
    /** @var array<string, string> lease name => the owner string it was taken under */
    private array $held = [];

    private readonly KitTables $tables;
    private readonly KitOptions $options;
    private readonly Clock $clock;

    public function __construct(
        private readonly Connection $db,
        ?KitTables $tables = null,
        ?KitOptions $options = null,
        ?Clock $clock = null,
    ) {
        $this->tables = $tables ?? new KitTables();
        $this->options = $options ?? $db->options();
        $this->clock = $clock ?? new SystemClock();
    }

    /**
     * Take the lease called $name for $ttl seconds (KitOptions::$lockTtl when
     * null). True when this object now holds it; false when somebody else
     * holds it and their lease has not expired.
     *
     * Taking a lease this object already holds takes it again: the row gets a
     * fresh owner and expiry, so it behaves like a renewal.
     */
    public function acquire(string $name, ?int $ttl = null): bool
    {
        $name = self::name($name);
        $ttl = max(1, $ttl ?? $this->options->lockTtl);
        $now = $this->clock->now();
        $owner = $this->newOwner();
        $table = $this->tables->locks;

        // An expired lease, or one this object already holds, is taken over in place.
        $mine = $this->held[$name] ?? null;
        $taken = $this->db->execute(
            "UPDATE {$table} SET locked_by = ?, locked_at = ?, expires_at = ?
             WHERE name = ? AND (expires_at < ? OR locked_by = ?)",
            [$owner, $now, $now + $ttl, $name, $now, $mine ?? '']
        );

        if ($taken !== 1) {
            // No row at all, or somebody else's live one. Insert-if-absent,
            // then look at who the row says holds it.
            $this->db->upsert($table, [
                'name' => $name,
                'locked_by' => $owner,
                'locked_at' => $now,
                'expires_at' => $now + $ttl,
            ], ['name'], []);

            $holder = $this->db->fetchValue("SELECT locked_by FROM {$table} WHERE name = ?", [$name]);
            if ($holder !== $owner) {
                // The owner is deliberately not recorded here: a failed attempt
                // that overwrote it would make the holder's own release delete
                // nothing and lock the name out until the lease expired.
                unset($this->held[$name]);

                return false;
            }
        }

        $this->held[$name] = $owner;

        return true;
    }

    /**
     * Push the expiry of a lease this object holds $ttl seconds past now. False
     * when it is no longer ours (it expired and somebody took it over), which
     * a long-running holder should treat as "stop".
     */
    public function renew(string $name, ?int $ttl = null): bool
    {
        $name = self::name($name);
        $owner = $this->held[$name] ?? null;
        if ($owner === null) {
            return false;
        }

        $now = $this->clock->now();
        $ttl = max(1, $ttl ?? $this->options->lockTtl);

        $renewed = $this->db->execute(
            "UPDATE {$this->tables->locks} SET expires_at = ? WHERE name = ? AND locked_by = ?",
            [$now + $ttl, $name, $owner]
        ) === 1;

        if (!$renewed) {
            unset($this->held[$name]);
        }

        return $renewed;
    }

    /** Let go of a lease this object holds. A no-op for one it does not. */
    public function release(string $name): void
    {
        $name = self::name($name);
        $owner = $this->held[$name] ?? null;
        if ($owner === null) {
            return;
        }

        unset($this->held[$name]);
        $this->db->execute(
            "DELETE FROM {$this->tables->locks} WHERE name = ? AND locked_by = ?",
            [$name, $owner]
        );
    }

    /** Whether this object took $name and has not released it. Says nothing about expiry. */
    public function holds(string $name): bool
    {
        return isset($this->held[self::name($name)]);
    }

    /**
     * Run $fn while holding $name, and release it afterwards whatever $fn does.
     *
     * @template T
     * @param callable(): T $fn
     * @return T
     * @throws LeaseUnavailable when somebody else holds the lease
     */
    public function run(string $name, callable $fn, ?int $ttl = null): mixed
    {
        if (!$this->acquire($name, $ttl)) {
            throw new LeaseUnavailable("Lease '{$name}' is held by another process ({$this->tables->locks})");
        }

        try {
            return $fn();
        } finally {
            $this->release($name);
        }
    }

    /** The row's name column is 64 characters; longer names are cut, as every in-tree copy did. */
    private static function name(string $name): string
    {
        return substr($name, 0, 64);
    }

    /** The configured owner, cut to leave room for a random tail, then the tail. */
    private function newOwner(): string
    {
        return substr($this->options->owner(), 0, 51) . ':' . bin2hex(random_bytes(6));
    }
}
