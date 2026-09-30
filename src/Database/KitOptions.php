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
 *  - `persistentKey`: the name PDO files this plugin's persistent connections under, when a MySQL or PostgreSQL config turns on `persistent` (see ConnectionFactory). PDO hands one persistent handle to everyone who opens the same DSN, user and password under the same key, so the key is what stops the pickup reset in one plugin from rolling back a transaction another plugin has open on the same database. Each plugin sets its own, usually its slug; a `persistent_key` in the database config wins over it (see ConnectionFactory). The default is the namespace of the kit copy that builds the connection, which Strauss makes different in every plugin, so two plugins that forget still get handles of their own. A numeric string is refused because PDO reads it as a plain on/off flag and would share the handle with every `ATTR_PERSISTENT => true` caller.
 */
final readonly class KitOptions
{
    public string $savepointPrefix;

    public string $persistentKey;

    public function __construct(
        string $savepointPrefix = 'sp_',
        public ?string $lockOwner = null,
        public int $lockTtl = 600,
        string $persistentKey = __NAMESPACE__,
    ) {
        if (!preg_match('/^[A-Za-z_][A-Za-z0-9_]{0,40}$/', $savepointPrefix)) {
            throw new \InvalidArgumentException("Invalid savepoint prefix: {$savepointPrefix}");
        }
        if ($lockTtl < 1) {
            throw new \InvalidArgumentException('Lock TTL must be at least one second');
        }

        $this->savepointPrefix = $savepointPrefix;
        $this->persistentKey = self::checkPersistentKey($persistentKey);
    }

    /**
     * $key when PDO would keep it apart from other keys, else an exception: it must not be empty, numeric (PDO treats a numeric key as plain on/off) or contain a NUL byte.
     */
    public static function checkPersistentKey(string $key): string
    {
        if ($key === '' || is_numeric($key) || str_contains($key, "\0")) {
            throw new \InvalidArgumentException("Invalid persistent key: {$key}");
        }

        return $key;
    }

    /** The resolved owner string, at most 64 characters (the column's width). */
    public function owner(): string
    {
        $owner = $this->lockOwner ?? (gethostname() . ':' . getmypid());

        return substr($owner, 0, 64);
    }
}
