<?php

declare(strict_types=1);

namespace TrilbyMedia\GravDbKit\Testing;

use TrilbyMedia\GravDbKit\Database\Connection;
use TrilbyMedia\GravDbKit\Database\ConnectionFactory;
use TrilbyMedia\GravDbKit\Database\KitOptions;

/**
 * Builds a fresh, empty database for the engine a test run selects through
 * environment variables. With the default prefix:
 *
 *   GRAVDBKIT_TEST_ENGINE = sqlite (default) | mysql | pgsql
 *   GRAVDBKIT_TEST_MYSQL_DSN / GRAVDBKIT_TEST_MYSQL_USER / GRAVDBKIT_TEST_MYSQL_PASS
 *   GRAVDBKIT_TEST_PGSQL_DSN / GRAVDBKIT_TEST_PGSQL_USER / GRAVDBKIT_TEST_PGSQL_PASS
 *
 * A plugin passes its own prefix (`HELPDESK_TEST` reads `HELPDESK_TEST_ENGINE`
 * and so on), and the table prefixes it owns. Server engines get every table
 * under those prefixes dropped by fresh(); SQLite gets a brand-new file in the
 * temp directory, removed when the process ends.
 *
 * Asking for a server engine whose DSN is not set is an error, not a skip: a
 * run that was meant to cover MySQL must not pass by quietly covering nothing.
 * Tests that only want a server engine when one is configured ask available().
 */
final class EngineProvider
{
    /** @var list<string> directories made for SQLite files, removed at shutdown */
    private static array $scratch = [];

    private static bool $cleanupRegistered = false;

    private ?string $sqliteFile = null;

    /**
     * @param list<string> $tablePrefixes tables fresh() drops on a server engine
     */
    public function __construct(
        private readonly string $envPrefix = 'GRAVDBKIT_TEST',
        private readonly array $tablePrefixes = ['kit_'],
        private readonly ?KitOptions $options = null,
    ) {
    }

    /** 'sqlite' (default), 'mysql' or 'pgsql'. */
    public function engine(): string
    {
        return getenv($this->envPrefix . '_ENGINE') ?: 'sqlite';
    }

    /** Whether $engine can be reached with the environment this run has. */
    public function available(string $engine): bool
    {
        return match ($engine) {
            'sqlite' => \extension_loaded('pdo_sqlite'),
            'mysql', 'pgsql' => (getenv($this->envPrefix . '_' . strtoupper($engine) . '_DSN') ?: '') !== '',
            default => false,
        };
    }

    /** A fresh, empty database on the selected engine. */
    public function fresh(): Connection
    {
        return $this->freshOn($this->engine());
    }

    /** A fresh, empty database on a named engine, whatever the run selected. */
    public function freshOn(string $engine): Connection
    {
        return match ($engine) {
            'sqlite' => $this->freshSqlite(),
            'mysql', 'pgsql' => $this->freshServer($engine),
            default => throw new \RuntimeException("Unknown {$this->envPrefix}_ENGINE: {$engine}"),
        };
    }

    /**
     * A second connection to the SAME database fresh() last built, for lock
     * and claim contention tests.
     */
    public function sibling(): Connection
    {
        return match ($this->engine()) {
            'sqlite' => ConnectionFactory::sqlite(
                $this->sqliteFile ?? throw new \RuntimeException('Call fresh() before sibling()'),
                $this->options
            ),
            default => $this->connectServer($this->engine()),
        };
    }

    public function sqliteFile(): ?string
    {
        return $this->sqliteFile;
    }

    /** Drop every table under this provider's prefixes (server engines and SQLite alike). */
    public function dropTables(Connection $c): void
    {
        $tables = array_values(array_filter(
            $this->tableNames($c),
            fn (string $table): bool => $this->owned($table)
        ));
        if ($tables === []) {
            return;
        }

        $d = $c->dialect();
        [$before, $suffix, $after] = match ($d->name()) {
            'mysql' => ['SET FOREIGN_KEY_CHECKS = 0', '', 'SET FOREIGN_KEY_CHECKS = 1'],
            'pgsql' => [null, ' CASCADE', null],
            default => ['PRAGMA foreign_keys=OFF', '', 'PRAGMA foreign_keys=ON'],
        };

        if ($before !== null) {
            $c->run($before);
        }
        foreach ($tables as $table) {
            $c->run('DROP TABLE IF EXISTS ' . $d->quoteIdentifier($table) . $suffix);
        }
        if ($after !== null) {
            $c->run($after);
        }
    }

    private function freshSqlite(): Connection
    {
        $dir = sys_get_temp_dir() . '/gravdbkit-test-' . bin2hex(random_bytes(6));
        self::registerScratch($dir);
        $this->sqliteFile = $dir . '/db/test.sqlite';

        return ConnectionFactory::sqlite($this->sqliteFile, $this->options);
    }

    private function freshServer(string $engine): Connection
    {
        $connection = $this->connectServer($engine);
        $this->dropTables($connection);

        return $connection;
    }

    private function connectServer(string $engine): Connection
    {
        $prefix = $this->envPrefix . '_' . strtoupper($engine);
        $dsn = getenv("{$prefix}_DSN");
        if (!$dsn) {
            throw new \RuntimeException("{$prefix}_DSN is not set");
        }

        $pdo = new \PDO(
            $dsn,
            getenv("{$prefix}_USER") ?: '',
            getenv("{$prefix}_PASS") ?: '',
            [
                \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION,
                \PDO::ATTR_DEFAULT_FETCH_MODE => \PDO::FETCH_ASSOC,
                \PDO::ATTR_EMULATE_PREPARES => false,
            ]
        );

        return ConnectionFactory::fromPdo($pdo, $engine, $this->options);
    }

    /** @return list<string> */
    private function tableNames(Connection $c): array
    {
        $sql = match ($c->dialect()->name()) {
            'mysql' => 'SELECT table_name AS t FROM information_schema.tables WHERE table_schema = DATABASE()',
            'pgsql' => 'SELECT tablename AS t FROM pg_tables WHERE schemaname = current_schema()',
            default => "SELECT name AS t FROM sqlite_master WHERE type = 'table' AND name NOT LIKE 'sqlite_%'",
        };

        return array_map(static fn (array $row): string => (string)($row['t'] ?? $row['T'] ?? ''), $c->fetchAll($sql));
    }

    private function owned(string $table): bool
    {
        foreach ($this->tablePrefixes as $prefix) {
            if (str_starts_with($table, $prefix)) {
                return true;
            }
        }

        return false;
    }

    private static function registerScratch(string $dir): void
    {
        self::$scratch[] = $dir;

        if (self::$cleanupRegistered) {
            return;
        }
        self::$cleanupRegistered = true;

        register_shutdown_function(static function (): void {
            foreach (self::$scratch as $path) {
                self::removeTree($path);
            }
        });
    }

    private static function removeTree(string $path): void
    {
        if (!is_dir($path)) {
            return;
        }

        $items = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($path, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($items as $item) {
            $item->isDir() ? @rmdir($item->getPathname()) : @unlink($item->getPathname());
        }
        @rmdir($path);
    }
}
