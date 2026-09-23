<?php

declare(strict_types=1);

namespace TrilbyMedia\GravDbKit\Database;

/**
 * The few behaviours a plugin tunes that are not table names.
 *
 *  - `savepointPrefix`: what Connection names its nested-transaction
 *    savepoints (`{prefix}1`, `{prefix}2`, …). Forum Pro used `fp_sp_`,
 *    KahunaCart `cp_sp_`. Only visible in engine error messages, so it exists
 *    to keep those messages recognisable, not for correctness.
 *  - `lockOwner`: how a lease row says who holds it. Null means host and pid,
 *    which is what every in-tree copy used. Lease adds a random tail of its
 *    own to each acquisition, so two leases taken by one process never share
 *    an owner string.
 *  - `lockTtl`: how long a lease is good for when the caller does not say,
 *    and how long the migration lock lasts. Ten minutes, as before: a crashed
 *    migration locks the schema for at most that long.
 */
final readonly class KitOptions
{
    public string $savepointPrefix;

    public function __construct(
        string $savepointPrefix = 'sp_',
        public ?string $lockOwner = null,
        public int $lockTtl = 600,
    ) {
        if (!preg_match('/^[A-Za-z_][A-Za-z0-9_]{0,40}$/', $savepointPrefix)) {
            throw new \InvalidArgumentException("Invalid savepoint prefix: {$savepointPrefix}");
        }
        if ($lockTtl < 1) {
            throw new \InvalidArgumentException('Lock TTL must be at least one second');
        }

        $this->savepointPrefix = $savepointPrefix;
    }

    /** The resolved owner string, at most 64 characters (the column's width). */
    public function owner(): string
    {
        $owner = $this->lockOwner ?? (gethostname() . ':' . getmypid());

        return substr($owner, 0, 64);
    }
}
