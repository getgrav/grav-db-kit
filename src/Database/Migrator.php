<?php

declare(strict_types=1);

namespace TrilbyMedia\GravDbKit\Database;

/**
 * Up-only, per-step migration runner. Tracking tables are bootstrapped with
 * CREATE TABLE IF NOT EXISTS; applies run under a database run-lock so
 * concurrent requests/CLI invocations cannot interleave DDL.
 *
 * Recovery model: there are no down migrations — restore from backup.
 * Until the first tagged release, migration files may be rewritten (squash
 * policy); after that they are append-only.
 *
 * More than one directory can be handed in, which is how an add-on plugin gets
 * its own tables into the same database and the same tracking table as the
 * base schema (KahunaCart's `onKahunaCartRegisterMigrations`). Order matters:
 * paths run in the order given and files sort by name *within* a path, so the
 * base schema is always in place before an add-on's first step runs. Names are
 * global — one tracking row per (migration, step) — so two directories
 * offering the same migration name is a collision this refuses to guess at.
 *
 * The tracking and lock tables are named by KitTables, so a plugin that
 * switches from its in-tree migrator keeps reading the rows it already wrote.
 * The run-lock is a Lease named `migrate` with KitOptions::$lockTtl.
 */
final class Migrator
{
    public const LOCK_NAME = 'migrate';

    /** @var list<string> */
    private readonly array $paths;

    private readonly KitTables $tables;
    private readonly KitOptions $options;

    /** @var array<string, Migration>|null */
    private ?array $migrations = null;

    /**
     * Every migration file read so far, keyed by its path and how it looked on
     * disk, so one file is read once per process however many migrators run
     * over it.
     *
     * Migration files are `return new class implements Migration`, and PHP
     * registers each anonymous class it compiles under a name of its own that
     * nothing ever frees — the compiler has no way to know it just compiled the
     * same declaration. So `require`ing the same file twice leaves two classes
     * behind, and a process that migrates over and over grows by the whole
     * weight of every migration's compiled methods each time. The test suite
     * builds a migrated in-memory database per test and paid about a megabyte
     * per database for it; a store on auto-migrate pays it on every request
     * that checks whether the schema is current.
     *
     * Sharing one instance is safe because migrations hold no state: a name and
     * a list of steps, and `steps()` builds its closures fresh on every call.
     *
     * The key carries each file's modification time and size, so an edited,
     * replaced or newly written migration is read again rather than remembered
     * — which is what keeps the tests that write migrations into a scratch
     * directory honest.
     *
     * @var array<string, mixed> whatever each file returned, checked on use
     */
    private static array $files = [];

    /**
     * @param string|list<string> $migrationsPaths one directory, or several in run order
     */
    public function __construct(
        private readonly Connection $connection,
        string|array $migrationsPaths,
        ?KitTables $tables = null,
        ?KitOptions $options = null,
    ) {
        $this->tables = $tables ?? new KitTables();
        $this->options = $options ?? $connection->options();

        $paths = \is_string($migrationsPaths) ? [$migrationsPaths] : $migrationsPaths;

        $this->paths = array_values(array_map(
            static fn (string $path): string => rtrim($path, '/'),
            $paths
        ));
    }

    /**
     * The directories this migrator reads, in run order — which is also the
     * only way to tell from outside whether an add-on's registration landed.
     *
     * @return list<string>
     */
    public function paths(): array
    {
        return $this->paths;
    }

    public function connection(): Connection
    {
        return $this->connection;
    }

    public function tables(): KitTables
    {
        return $this->tables;
    }

    /**
     * A fingerprint of the migration files under a set of directories, without
     * reading any of them.
     *
     * Answering "is the schema current?" honestly costs a database connection,
     * two DDL statements, a read of the tracking table and a `require` of every
     * migration file. A store on the auto-migrate default asks that on every
     * web request, and the answer is almost always "yes, nothing to do". This
     * is what makes remembering the answer safe: the name, size and
     * modification time of every `*.php` in every directory, which changes when
     * a migration is added, removed, edited, or when an add-on brings its own
     * directory into the set — including a step appended to a file that already
     * existed, which a directory mtime alone would miss. One `glob()` and one
     * `stat()` per file.
     *
     * @param list<string> $paths
     */
    public static function fingerprint(array $paths): string
    {
        $parts = [];

        foreach ($paths as $path) {
            foreach (glob(rtrim($path, '/') . '/*.php') ?: [] as $file) {
                $parts[] = $file . ':' . (int)@filemtime($file) . ':' . (int)@filesize($file);
            }
        }

        sort($parts);

        return substr(hash('xxh128', implode('|', $parts)), 0, 16);
    }

    public function bootstrap(): void
    {
        $d = $this->connection->dialect();
        $t = $this->tables;

        $this->connection->run(
            "CREATE TABLE IF NOT EXISTS {$t->locks} (
                name VARCHAR(64) NOT NULL PRIMARY KEY,
                locked_by VARCHAR(64) NOT NULL,
                locked_at BIGINT NOT NULL,
                expires_at BIGINT NOT NULL
            ) {$d->tableOptions()}"
        );

        $this->connection->run(
            "CREATE TABLE IF NOT EXISTS {$t->migrations} (
                id {$d->primaryKey()},
                migration VARCHAR(120) NOT NULL,
                step VARCHAR(120) NOT NULL,
                applied_at BIGINT NOT NULL,
                CONSTRAINT {$t->migrationsUnique} UNIQUE (migration, step)
            ) {$d->tableOptions()}"
        );
    }

    /**
     * @return array<string, Migration> name => migration, path order then filename order
     */
    public function migrations(): array
    {
        if ($this->migrations !== null) {
            return $this->migrations;
        }

        $migrations = [];
        $origin = [];

        foreach ($this->paths as $path) {
            $files = glob($path . '/*.php') ?: [];
            sort($files);

            foreach ($files as $file) {
                $migration = self::read($file);
                if (!$migration instanceof Migration) {
                    throw new \RuntimeException("Migration file does not return a Migration instance: {$file}");
                }
                $expected = basename($file, '.php');
                if ($migration->name() !== $expected) {
                    throw new \RuntimeException("Migration name '{$migration->name()}' does not match filename '{$expected}'");
                }
                $name = $migration->name();
                if (isset($migrations[$name])) {
                    // Two files claiming one name would write one tracking row
                    // between them and silently skip the second one's steps.
                    throw new \RuntimeException(
                        "Duplicate migration name '{$name}': {$origin[$name]} and {$file}"
                    );
                }
                $migrations[$name] = $migration;
                $origin[$name] = $file;
            }
        }

        return $this->migrations = $migrations;
    }

    /**
     * One migration file, read from disk the first time it is asked for and
     * remembered after that. See `self::$files` for why.
     *
     * The return type is wide on purpose: a file that returns something that is
     * not a migration is the caller's error to report, with the filename in the
     * message, rather than a type error from here.
     */
    private static function read(string $file): mixed
    {
        $key = $file . ':' . (int)@filemtime($file) . ':' . (int)@filesize($file);

        if (!\array_key_exists($key, self::$files)) {
            self::$files[$key] = require $file;
        }

        return self::$files[$key];
    }

    /**
     * Forget every migration file read so far — for a long-running process that
     * has just written or replaced migration files itself and wants the next
     * migrator to read the directory afresh rather than trust the stat it took.
     */
    public static function forgetLoadedFiles(): void
    {
        self::$files = [];
    }

    /**
     * @return array<string, true> "migration:step" => true
     */
    public function appliedSteps(): array
    {
        $rows = $this->connection->fetchAll("SELECT migration, step FROM {$this->tables->migrations}");

        $applied = [];
        foreach ($rows as $row) {
            $applied[$row['migration'] . ':' . $row['step']] = true;
        }

        return $applied;
    }

    /**
     * @return array<string, list<string>> migration name => pending step names
     */
    public function pending(): array
    {
        $this->bootstrap();
        $applied = $this->appliedSteps();
        $dialect = $this->connection->dialect();

        $pending = [];
        foreach ($this->migrations() as $name => $migration) {
            foreach (array_keys($migration->steps($dialect)) as $step) {
                if (!isset($applied[$name . ':' . $step])) {
                    $pending[$name][] = $step;
                }
            }
        }

        return $pending;
    }

    public function isUpToDate(): bool
    {
        return $this->pending() === [];
    }

    /**
     * Apply all pending steps. Returns the number of steps applied.
     *
     * @param callable(string $migration, string $step): void|null $progress
     */
    public function migrate(?callable $progress = null): int
    {
        $this->bootstrap();

        $lease = new Lease($this->connection, $this->tables, $this->options);
        if (!$lease->acquire(self::LOCK_NAME, $this->options->lockTtl)) {
            throw new \RuntimeException("Another migration is already running ({$this->tables->locks}: " . self::LOCK_NAME . ')');
        }

        $appliedCount = 0;
        try {
            $applied = $this->appliedSteps();
            $dialect = $this->connection->dialect();

            foreach ($this->migrations() as $name => $migration) {
                foreach ($migration->steps($dialect) as $step => $fn) {
                    if (isset($applied[$name . ':' . $step])) {
                        continue;
                    }

                    $fn($this->connection);

                    $this->connection->insert($this->tables->migrations, [
                        'migration' => $name,
                        'step' => $step,
                        'applied_at' => time(),
                    ]);
                    $appliedCount++;

                    if ($progress !== null) {
                        $progress($name, $step);
                    }
                }
            }
        } finally {
            $lease->release(self::LOCK_NAME);
        }

        return $appliedCount;
    }
}
