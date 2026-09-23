<?php

declare(strict_types=1);

namespace TrilbyMedia\GravDbKit\Database;

/**
 * The "is the schema current, and should I bring it up to date?" check a
 * plugin runs on every request, made cheap enough to run on every request.
 *
 * Asking the migrator honestly costs two CREATE TABLE IF NOT EXISTS
 * statements, a read of the tracking table and a `require` of every migration
 * file, to conclude almost every time that nothing has changed. So the answer
 * is remembered in a SchemaStateStore against Migrator::fingerprint() of the
 * migration directories (name, size and mtime of every file). A match skips
 * everything; adding, removing or editing a migration file, or an add-on
 * bringing its own directory into the set, changes the fingerprint and the
 * full check runs once more.
 *
 * Policies (the plugin's `auto_migrate` setting):
 *
 *  - `auto`: apply pending steps on any engine.
 *  - `sqlite`: apply them only when the database is SQLite, the zero-admin
 *    default. Server databases are migrated from the CLI, where a long DDL
 *    run cannot be cut short by a web request timeout.
 *  - `sqlite-only`: the same as `sqlite` (Forum Pro's spelling).
 *  - `manual`: never apply; only report what is pending.
 *
 * Under a policy that does not apply, pending steps are counted and reported,
 * and the fingerprint is not recorded until a CLI run has brought the schema
 * up to date, so the check keeps running until then.
 *
 * Run this outside any open transaction: MySQL commits DDL implicitly, and a
 * failed read inside a PostgreSQL transaction poisons the rest of it.
 */
final class SchemaGuard
{
    public const AUTO = 'auto';
    public const SQLITE = 'sqlite';
    public const SQLITE_ONLY = 'sqlite-only';
    public const MANUAL = 'manual';

    private function __construct()
    {
    }

    /**
     * @return array{policy: string, pending: int, applied: int}
     *         the policy as given, steps still pending after this call, steps
     *         applied by it
     */
    public static function ensure(Migrator $migrator, SchemaStateStore $state, string $policy): array
    {
        $normalized = self::normalizePolicy($policy);
        $fingerprint = Migrator::fingerprint($migrator->paths());

        if ($state->remembered() === $fingerprint) {
            return ['policy' => $policy, 'pending' => 0, 'applied' => 0];
        }

        $pending = self::countSteps($migrator->pending());
        $applied = 0;

        if ($pending > 0 && self::allows($normalized, $migrator->connection()->dialect()->name())) {
            $applied = $migrator->migrate();
            $pending = self::countSteps($migrator->pending());
        }

        if ($pending === 0) {
            $state->remember($fingerprint);
        }

        return ['policy' => $policy, 'pending' => $pending, 'applied' => $applied];
    }

    /**
     * `sqlite-only` becomes `sqlite`; anything that is not a known policy is
     * refused, because a typo that quietly meant "manual" would leave a site
     * on an old schema with nobody told.
     */
    public static function normalizePolicy(string $policy): string
    {
        return match ($policy) {
            self::AUTO, self::SQLITE, self::MANUAL => $policy,
            self::SQLITE_ONLY => self::SQLITE,
            default => throw new \InvalidArgumentException(
                "Unknown migration policy '{$policy}' (expected auto, sqlite, sqlite-only or manual)"
            ),
        };
    }

    /** Whether $policy lets a web request apply migrations on $engine ('sqlite' | 'mysql' | 'pgsql'). */
    public static function allows(string $policy, string $engine): bool
    {
        return match (self::normalizePolicy($policy)) {
            self::AUTO => true,
            self::SQLITE => $engine === 'sqlite',
            default => false,
        };
    }

    /** @param array<string, list<string>> $pending */
    private static function countSteps(array $pending): int
    {
        return array_sum(array_map('count', $pending));
    }
}
