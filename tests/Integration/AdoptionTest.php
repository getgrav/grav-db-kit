<?php

declare(strict_types=1);

namespace TrilbyMedia\GravDbKit\Tests\Integration;

use PHPUnit\Framework\TestCase;
use TrilbyMedia\GravDbKit\Database\CallbackSchemaState;
use TrilbyMedia\GravDbKit\Database\Connection;
use TrilbyMedia\GravDbKit\Database\ConnectionFactory;
use TrilbyMedia\GravDbKit\Database\KitOptions;
use TrilbyMedia\GravDbKit\Database\KitTables;
use TrilbyMedia\GravDbKit\Database\KvSchemaState;
use TrilbyMedia\GravDbKit\Database\Migrator;
use TrilbyMedia\GravDbKit\Database\SchemaGuard;
use TrilbyMedia\GravDbKit\Support\KvStore;

/**
 * The kit's migrator pointed at a real plugin database, as the plugin would
 * point it on the day it switches from its in-tree copy.
 *
 * For each plugin this takes a COPY of a live development database (the
 * original is only ever read by `copy()`), and the plugin's own migrations
 * directory with the one change a switching plugin makes to its migration
 * files: the three `use` lines for Connection, Dialect and Migration point at
 * the kit instead of the plugin's in-tree classes. Then:
 *
 *  1. the kit and the plugin's own in-tree Migrator report exactly the same
 *     pending steps for the same database (both zero when the site is
 *     current);
 *  2. the kit applies exactly those steps and then reports zero pending, and
 *     its tracking rows match the ones the in-tree migrator writes for the
 *     same run;
 *  3. once the in-tree migrator has brought a copy current, the kit reports
 *     zero pending on it and a migrate() is a no-op — nothing already
 *     applied runs twice;
 *  4. SchemaGuard settles on the database with the plugin's own schema state.
 *
 * The databases live outside this repository, so the test skips (saying
 * where it looked) when they are absent, as they are on CI. Point it at other
 * copies with GRAVDBKIT_ADOPT_{FORUM,KAHUNACART}_{DB,PLUGIN}.
 */
final class AdoptionTest extends TestCase
{
    /** @var list<string> */
    private array $scratch = [];

    /** @var array<string, true> namespaces whose in-tree classes are autoloadable */
    private static array $registered = [];

    protected function tearDown(): void
    {
        foreach ($this->scratch as $dir) {
            foreach (glob($dir . '/{,.}*', GLOB_BRACE) ?: [] as $file) {
                if (is_file($file)) {
                    unlink($file);
                }
            }
            foreach (glob($dir . '/*', GLOB_ONLYDIR) ?: [] as $sub) {
                array_map('unlink', glob($sub . '/*') ?: []);
                rmdir($sub);
            }
            rmdir($dir);
        }
        $this->scratch = [];
    }

    public function testForumProDatabaseAdoptsWithItsOwnTableNames(): void
    {
        $plugin = $this->pluginDir('FORUM', 'grav-plugin-forum-pro');
        $database = $this->database('FORUM', '/workspace/grav-forum/user/data/forum-pro/db/forum.sqlite');

        $tables = new KitTables(
            migrations: 'forum_migrations',
            locks: 'forum_locks',
            kv: 'forum_kv',
            jobs: 'forum_jobs',
            rateLimits: 'forum_rate_limits',
        );
        $options = new KitOptions(savepointPrefix: 'fp_sp_');

        $kitDb = $this->adopt($plugin, $database, 'ForumPro', $tables, $options);

        // Forum Pro's own schema state: `migrations.complete` in forum_kv. The
        // value Forum Pro stored is its own sha256 of basenames, so the first
        // guarded request runs the full check once, finds nothing to do, and
        // records the kit's fingerprint in the same row.
        $state = new KvSchemaState(new KvStore($kitDb, $tables));
        $migrator = new Migrator($kitDb, $this->kitMigrations($plugin, 'ForumPro'), $tables, $options);

        self::assertSame(
            ['policy' => 'sqlite-only', 'pending' => 0, 'applied' => 0],
            SchemaGuard::ensure($migrator, $state, 'sqlite-only')
        );
        self::assertSame(Migrator::fingerprint($migrator->paths()), $state->remembered());
        self::assertSame(
            ['policy' => 'sqlite-only', 'pending' => 0, 'applied' => 0],
            SchemaGuard::ensure($migrator, $state, 'sqlite-only'),
            'and the next request takes the fast path'
        );
    }

    public function testKahunaCartDatabaseAdoptsWithItsOwnTableNames(): void
    {
        $plugin = $this->pluginDir('KAHUNACART', 'grav-plugin-kahunacart');
        $database = $this->database('KAHUNACART', '/workspace/grav-kahunacart/user/data/kahunacart/db/kahunacart.sqlite');

        $tables = KitTables::withPrefix('kahunacart');
        $options = new KitOptions(savepointPrefix: 'cp_sp_');

        $kitDb = $this->adopt($plugin, $database, 'KahunaCart', $tables, $options);

        // KahunaCart keeps its schema state in Grav's cache, which is what
        // CallbackSchemaState stands in for here.
        $cache = [];
        $state = new CallbackSchemaState(
            static function () use (&$cache): ?string {
                return $cache['kahunacart-schema'] ?? null;
            },
            static function (string $fingerprint) use (&$cache): void {
                $cache['kahunacart-schema'] = $fingerprint;
            }
        );
        $migrator = new Migrator($kitDb, $this->kitMigrations($plugin, 'KahunaCart'), $tables, $options);

        self::assertSame(['policy' => 'sqlite', 'pending' => 0, 'applied' => 0], SchemaGuard::ensure($migrator, $state, 'sqlite'));
        self::assertSame(Migrator::fingerprint($migrator->paths()), $cache['kahunacart-schema']);
    }

    /**
     * Steps 1 to 3 from the class comment. Returns a kit connection on a copy
     * that is now current.
     */
    private function adopt(string $plugin, string $database, string $shortName, KitTables $tables, KitOptions $options): Connection
    {
        $this->registerInTreeClasses($plugin, $shortName);
        $namespace = 'Grav\\Plugin\\' . $shortName . '\\Database\\';
        $factory = $namespace . 'ConnectionFactory';
        $inTreeMigrator = $namespace . 'Migrator';

        $dir = $this->scratchDir();
        $inTreeCopy = $this->copyDatabase($database, $dir . '/in-tree.sqlite');
        $kitCopy = $this->copyDatabase($database, $dir . '/kit.sqlite');
        $kitMigrations = $this->kitMigrations($plugin, $shortName);

        // 1. The same answer from both migrators, before anything runs.
        $inTreeDb = $factory::sqlite($inTreeCopy);
        $inTree = new $inTreeMigrator($inTreeDb, $plugin . '/migrations');
        $pendingInTree = $inTree->pending();

        $kitDb = ConnectionFactory::sqlite($kitCopy, $options);
        $kit = new Migrator($kitDb, $kitMigrations, $tables, $options);
        self::assertSame($pendingInTree, $kit->pending(), 'the kit sees exactly what the in-tree migrator sees');

        // 2. The kit applies exactly those steps, and then nothing is pending.
        $pendingCount = array_sum(array_map('count', $pendingInTree));
        self::assertSame($pendingCount, $kit->migrate());
        self::assertSame([], $kit->pending(), 'zero pending after the kit migrated its copy');

        // 3. The in-tree migrator brings its own copy current (what the plugin
        // itself would do on its next request), and the kit finds nothing to do.
        self::assertSame($pendingCount, $inTree->migrate());
        $adopted = new Migrator(ConnectionFactory::sqlite($inTreeCopy, $options), $kitMigrations, $tables, $options);
        self::assertSame([], $adopted->pending(), 'zero pending on a database the plugin brought current');
        self::assertSame(0, $adopted->migrate(), 'and nothing already applied runs again');

        self::assertSame(
            $this->trackedSteps($inTreeDb, $tables),
            $this->trackedSteps($kitDb, $tables),
            'both runs record the same (migration, step) rows'
        );
        self::assertSame(0, (int)$kitDb->fetchValue("SELECT COUNT(*) FROM {$tables->locks} WHERE name = 'migrate'"));

        fwrite(\STDERR, sprintf(
            "\n[adoption] %s: %d migrations, %d steps pending on the live copy%s\n",
            $shortName,
            \count($kit->migrations()),
            $pendingCount,
            $pendingCount > 0 ? ' (' . implode(', ', array_keys($pendingInTree)) . ')' : ''
        ));

        return $kitDb;
    }

    /**
     * The plugin's migrations directory as it would read after switching: a
     * copy with the in-tree Connection, Dialect and Migration names replaced by
     * the kit's. Anything else the files import (domain classes, KahunaCart's
     * SettingsTable) is left pointing at the plugin.
     */
    private function kitMigrations(string $plugin, string $shortName): string
    {
        static $copies = [];
        if (isset($copies[$plugin])) {
            return $copies[$plugin];
        }

        $target = sys_get_temp_dir() . '/gravdbkit-adopt-' . strtolower($shortName) . '-' . bin2hex(random_bytes(4));
        mkdir($target);
        register_shutdown_function(static function () use ($target): void {
            array_map('unlink', glob($target . '/*') ?: []);
            @rmdir($target);
        });

        $from = preg_quote('Grav\\Plugin\\' . $shortName . '\\Database\\', '/');
        foreach (glob($plugin . '/migrations/*.php') ?: [] as $file) {
            $source = (string)file_get_contents($file);
            $rewritten = preg_replace(
                '/\\\\?' . $from . '(Connection|Migration|Dialect\\\\Dialect)\b/',
                'TrilbyMedia\\GravDbKit\\Database\\\\$1',
                $source
            );
            file_put_contents($target . '/' . basename($file), $rewritten);
        }

        return $copies[$plugin] = $target;
    }

    /** @return list<string> "migration:step", sorted */
    private function trackedSteps(object $db, KitTables $tables): array
    {
        $rows = $db->fetchAll("SELECT migration, step FROM {$tables->migrations}");
        $steps = array_map(static fn (array $row): string => $row['migration'] . ':' . $row['step'], $rows);
        sort($steps);

        return $steps;
    }

    /**
     * The plugin's own `classes/` directory on the autoloader, appended, for
     * its in-tree migrator and anything its migration files import. The
     * plugin's vendor/autoload.php is deliberately not loaded: Composer
     * prepends its loader, and the plugin's own PHPUnit would then shadow ours.
     */
    private function registerInTreeClasses(string $plugin, string $shortName): void
    {
        $prefix = 'Grav\\Plugin\\' . $shortName . '\\';
        if (isset(self::$registered[$prefix])) {
            return;
        }
        self::$registered[$prefix] = true;

        spl_autoload_register(static function (string $class) use ($prefix, $plugin): void {
            if (!str_starts_with($class, $prefix)) {
                return;
            }
            $file = $plugin . '/classes/' . str_replace('\\', '/', substr($class, \strlen($prefix))) . '.php';
            if (is_file($file)) {
                require $file;
            }
        });
    }

    private function pluginDir(string $key, string $name): string
    {
        $candidates = array_filter([
            getenv("GRAVDBKIT_ADOPT_{$key}_PLUGIN") ?: null,
            \dirname(__DIR__, 3) . '/' . $name,
            \dirname(__DIR__, 4) . '/' . $name,
        ]);

        foreach ($candidates as $dir) {
            if (is_dir($dir . '/migrations') && is_dir($dir . '/classes/Database')) {
                return rtrim($dir, '/');
            }
        }

        self::markTestSkipped("No {$name} checkout found (looked in: " . implode(', ', $candidates) . "; set GRAVDBKIT_ADOPT_{$key}_PLUGIN)");
    }

    private function database(string $key, string $homeRelative): string
    {
        $path = getenv("GRAVDBKIT_ADOPT_{$key}_DB") ?: (getenv('HOME') ?: '') . $homeRelative;
        if (!is_file($path) || filesize($path) === 0) {
            self::markTestSkipped("No database to adopt at {$path} (set GRAVDBKIT_ADOPT_{$key}_DB)");
        }

        return $path;
    }

    /**
     * Read-only copy: the main file plus its write-ahead log when it has one,
     * so committed pages still in the WAL come along. The shared-memory index
     * is rebuilt by SQLite on open. The originals are never opened.
     */
    private function copyDatabase(string $from, string $to): string
    {
        self::assertTrue(copy($from, $to), "copy {$from}");
        if (is_file($from . '-wal') && filesize($from . '-wal') > 0) {
            self::assertTrue(copy($from . '-wal', $to . '-wal'));
        }

        return $to;
    }

    private function scratchDir(): string
    {
        $dir = sys_get_temp_dir() . '/gravdbkit-adopt-db-' . bin2hex(random_bytes(6));
        mkdir($dir);
        $this->scratch[] = $dir;

        return $dir;
    }
}
