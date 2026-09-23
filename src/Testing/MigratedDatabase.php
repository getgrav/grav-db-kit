<?php

declare(strict_types=1);

namespace TrilbyMedia\GravDbKit\Testing;

use TrilbyMedia\GravDbKit\Database\Connection;
use TrilbyMedia\GravDbKit\Database\ConnectionFactory;
use TrilbyMedia\GravDbKit\Database\KitOptions;
use TrilbyMedia\GravDbKit\Database\KitTables;
use TrilbyMedia\GravDbKit\Database\Migrator;

/**
 * A freshly migrated database per test, cheap enough to build thousands of.
 *
 * On SQLite the first call migrates an in-memory database for real and
 * snapshots the result as one SQL script (schema and seed rows); every later
 * call replays the script into a new in-memory database. In KahunaCart that
 * is under ten milliseconds against about ninety for running the migrations,
 * and the difference between a six-minute suite and a one-minute one. The
 * snapshot is kept per set of migration directories and tables, so two
 * plugins' suites in one process never share one.
 *
 * On MySQL and PostgreSQL (selected through the EngineProvider's environment
 * variables) there is no snapshot: every call drops the provider's tables and
 * migrates from scratch, which is slow and is what a multi-engine run is for.
 *
 * Set `{PREFIX}_MIGRATE_EACH=1` (GRAVDBKIT_TEST_MIGRATE_EACH by default) to
 * migrate for every SQLite database as well, which is the right mode when a
 * migration itself is under test and the snapshot would hide a change.
 */
final class MigratedDatabase
{
    /** @var array<string, string> snapshot key => SQL script */
    private static array $templates = [];

    /** @var list<string> */
    private readonly array $paths;

    private readonly KitTables $tables;
    private readonly EngineProvider $engines;

    /**
     * @param string|list<string> $paths migration directories, in run order
     */
    public function __construct(
        string|array $paths,
        ?KitTables $tables = null,
        ?EngineProvider $engines = null,
        private readonly ?KitOptions $options = null,
        private readonly string $envPrefix = 'GRAVDBKIT_TEST',
    ) {
        $this->paths = \is_string($paths) ? [$paths] : array_values($paths);
        $this->tables = $tables ?? new KitTables();
        $this->engines = $engines ?? new EngineProvider($envPrefix, self::dropPrefixes($this->tables), $options);
    }

    /** A migrated database on the engine this run selected. */
    public function connection(): Connection
    {
        if ($this->engines->engine() === 'sqlite') {
            return $this->sqlite();
        }

        $connection = $this->engines->fresh();
        $this->migrator($connection)->migrate();

        return $connection;
    }

    /** A migrated in-memory SQLite database, whatever engine the run selected. */
    public function sqlite(): Connection
    {
        if ((getenv($this->envPrefix . '_MIGRATE_EACH') ?: '') !== '') {
            return $this->migratedSqlite();
        }

        $key = $this->snapshotKey();
        self::$templates[$key] ??= self::snapshot($this->migratedSqlite());

        $connection = ConnectionFactory::sqlite(':memory:', $this->options);
        $pdo = $connection->pdo();
        $pdo->exec('PRAGMA foreign_keys=OFF');
        $pdo->exec(self::$templates[$key]);
        $pdo->exec('PRAGMA foreign_keys=ON');

        return $connection;
    }

    /** A migrator over an already-open connection, with this database's paths and tables. */
    public function migrator(Connection $connection): Migrator
    {
        return new Migrator($connection, $this->paths, $this->tables, $this->options);
    }

    public function engines(): EngineProvider
    {
        return $this->engines;
    }

    /** Throw every snapshot away, for a test that has just rewritten migration files. */
    public static function forgetSnapshots(): void
    {
        self::$templates = [];
    }

    private function migratedSqlite(): Connection
    {
        $connection = ConnectionFactory::sqlite(':memory:', $this->options);
        $this->migrator($connection)->migrate();

        return $connection;
    }

    private function snapshotKey(): string
    {
        return Migrator::fingerprint($this->paths) . '|' . implode(',', $this->paths) . '|' . implode(',', $this->tables->toArray());
    }

    /**
     * Everything `sqlite_master` holds, tables first, then indexes, then
     * views and triggers, followed by an INSERT per seed row. Shadow tables
     * behind a virtual table are skipped, because the CREATE VIRTUAL TABLE
     * statement makes them again; the virtual table's own rows are copied
     * like any other table's. Only tables are tested for the shadow prefix,
     * since a trigger on a search index shares it.
     */
    private static function snapshot(Connection $connection): string
    {
        $pdo = $connection->pdo();

        $virtual = [];
        foreach ($pdo->query("SELECT name FROM sqlite_master WHERE sql LIKE 'CREATE VIRTUAL TABLE%'") as $row) {
            $virtual[] = (string)$row['name'];
        }
        $isShadow = static function (string $name) use ($virtual): bool {
            foreach ($virtual as $owner) {
                if ($name !== $owner && str_starts_with($name, $owner . '_')) {
                    return true;
                }
            }

            return false;
        };

        $statements = [];
        $tables = [];
        $objects = $pdo->query(
            "SELECT type, name, sql FROM sqlite_master
             WHERE sql IS NOT NULL AND name NOT LIKE 'sqlite_%'
             ORDER BY CASE type WHEN 'table' THEN 0 WHEN 'index' THEN 1 WHEN 'view' THEN 2 ELSE 3 END, rowid"
        );
        foreach ($objects as $row) {
            if ($row['type'] === 'table' && $isShadow((string)$row['name'])) {
                continue;
            }
            $statements[] = $row['sql'] . ';';
            if ($row['type'] === 'table') {
                $tables[] = (string)$row['name'];
            }
        }

        foreach ($tables as $table) {
            foreach ($pdo->query('SELECT * FROM "' . $table . '"') as $record) {
                $columns = [];
                $values = [];
                foreach ($record as $column => $value) {
                    $columns[] = '"' . $column . '"';
                    $values[] = $value === null ? 'NULL' : $pdo->quote((string)$value);
                }
                $statements[] = 'INSERT INTO "' . $table . '" (' . implode(',', $columns) . ') VALUES (' . implode(',', $values) . ');';
            }
        }

        return implode("\n", $statements);
    }

    /**
     * What a server-engine run drops between tests when no EngineProvider was
     * handed in: the longest prefix every kit table shares (`helpdesk_` for
     * KitTables::withPrefix('helpdesk')), or the kit tables themselves by name
     * when they share none. A plugin whose own tables live under another
     * prefix passes its own provider, or its tables survive from one test to
     * the next.
     *
     * @return list<string>
     */
    private static function dropPrefixes(KitTables $tables): array
    {
        $names = [$tables->migrations, $tables->locks, $tables->kv, $tables->jobs, $tables->rateLimits];
        $prefix = $names[0];
        foreach ($names as $name) {
            while ($prefix !== '' && !str_starts_with($name, $prefix)) {
                $prefix = substr($prefix, 0, -1);
            }
        }

        $cut = strrpos($prefix, '_');
        $prefix = $cut === false ? '' : substr($prefix, 0, $cut + 1);

        // Never an empty prefix: "every table starting with nothing" is the
        // whole test database, which may not be ours alone.
        return $prefix === '' ? $names : [$prefix];
    }
}
