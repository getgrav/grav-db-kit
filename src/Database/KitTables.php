<?php

declare(strict_types=1);

namespace TrilbyMedia\GravDbKit\Database;

/**
 * The names of every table the kit itself reads or writes.
 *
 * The kit never writes a table name inline: each plugin that bundles it owns
 * its tables under its own prefix, and a plugin switching from an in-tree copy
 * of this code keeps the names it already has, so no row ever moves. Forum Pro
 * passes `forum_migrations` and `forum_locks`, KahunaCart passes the
 * `kahunacart_*` names, and a new plugin can take them all from one prefix:
 *
 *     KitTables::withPrefix('helpdesk')  // helpdesk_migrations, helpdesk_locks, …
 *
 * `migrationsUnique` is the name of the UNIQUE (migration, step) constraint the
 * migrator's bootstrap creates. It defaults to `uq_` plus the migrations table,
 * which is what both plugins already have; it only matters on a fresh database,
 * because the bootstrap is CREATE TABLE IF NOT EXISTS.
 *
 * Every name is validated as a plain SQL identifier here, once, so the classes
 * that interpolate them into SQL never have to.
 */
final readonly class KitTables
{
    public string $migrations;
    public string $migrationsUnique;
    public string $locks;
    public string $kv;
    public string $jobs;
    public string $rateLimits;

    public function __construct(
        string $migrations = 'kit_migrations',
        string $locks = 'kit_locks',
        string $kv = 'kit_kv',
        string $jobs = 'kit_jobs',
        string $rateLimits = 'kit_rate_limits',
        ?string $migrationsUnique = null,
    ) {
        $this->migrations = self::identifier($migrations);
        $this->migrationsUnique = self::identifier($migrationsUnique ?? 'uq_' . $migrations);
        $this->locks = self::identifier($locks);
        $this->kv = self::identifier($kv);
        $this->jobs = self::identifier($jobs);
        $this->rateLimits = self::identifier($rateLimits);
    }

    /**
     * Every table under one prefix: `{prefix}_migrations`, `{prefix}_locks`,
     * `{prefix}_kv`, `{prefix}_jobs`, `{prefix}_rate_limits`.
     */
    public static function withPrefix(string $prefix): self
    {
        $prefix = rtrim($prefix, '_');

        return new self(
            migrations: $prefix . '_migrations',
            locks: $prefix . '_locks',
            kv: $prefix . '_kv',
            jobs: $prefix . '_jobs',
            rateLimits: $prefix . '_rate_limits',
        );
    }

    /** @return array<string, string> accessor name => table name */
    public function toArray(): array
    {
        return [
            'migrations' => $this->migrations,
            'migrationsUnique' => $this->migrationsUnique,
            'locks' => $this->locks,
            'kv' => $this->kv,
            'jobs' => $this->jobs,
            'rateLimits' => $this->rateLimits,
        ];
    }

    private static function identifier(string $name): string
    {
        if (!preg_match('/^[A-Za-z_][A-Za-z0-9_]{0,62}$/', $name)) {
            throw new \InvalidArgumentException("Invalid table name: {$name}");
        }

        return $name;
    }
}
