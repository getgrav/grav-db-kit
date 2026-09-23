<?php

declare(strict_types=1);

namespace TrilbyMedia\GravDbKit\Tests\Integration;

use PHPUnit\Framework\TestCase;
use TrilbyMedia\GravDbKit\Database\Connection;
use TrilbyMedia\GravDbKit\Database\KitOptions;
use TrilbyMedia\GravDbKit\Database\KitTables;
use TrilbyMedia\GravDbKit\Database\Lease;
use TrilbyMedia\GravDbKit\Database\Migrator;
use TrilbyMedia\GravDbKit\Tests\Support\TestEngine;

final class MigratorTest extends TestCase
{
    /** @var list<string> directories built by makePath(), removed in tearDown */
    private array $tempPaths = [];

    private Connection $db;

    protected function setUp(): void
    {
        $this->db = TestEngine::fresh();
    }

    protected function tearDown(): void
    {
        foreach ($this->tempPaths as $path) {
            foreach (glob($path . '/*.php') ?: [] as $file) {
                unlink($file);
            }
            rmdir($path);
        }
        $this->tempPaths = [];

        parent::tearDown();
    }

    public function testMigrateAppliesAllStepsOnce(): void
    {
        $migrator = new Migrator($this->db, TestEngine::migrationsPath());

        self::assertSame(5, $migrator->migrate());
        self::assertTrue($migrator->isUpToDate());
        self::assertSame(0, $migrator->migrate(), 'second run must be a no-op');
        self::assertSame(5, (int)$this->db->fetchValue('SELECT COUNT(*) FROM kit_migrations'));

        $d = $this->db->dialect();
        foreach (['kit_migrations', 'kit_locks', 'kit_kv', 'kit_jobs', 'kit_rate_limits', 'kit_t_widgets'] as $table) {
            self::assertTrue($d->tableExists($this->db->pdo(), $table), "missing table: {$table}");
        }
    }

    public function testPendingListsStepsByMigrationInRunOrder(): void
    {
        $migrator = new Migrator($this->db, TestEngine::migrationsPath());

        self::assertSame([
            '0001_infra' => ['create_kit_kv', 'create_kit_jobs', 'create_kit_rate_limits'],
            '0002_widgets' => ['create_kit_t_widgets', 'seed_kit_t_widgets'],
        ], $migrator->pending());

        $migrator->migrate();
        $this->db->execute('DELETE FROM kit_migrations WHERE step = ?', ['seed_kit_t_widgets']);

        self::assertSame(['0002_widgets' => ['seed_kit_t_widgets']], $migrator->pending());
        self::assertSame(1, $migrator->migrate(), 'only the forgotten step runs, and it is idempotent');
        self::assertSame(1, (int)$this->db->fetchValue("SELECT COUNT(*) FROM kit_t_widgets WHERE sku = 'seed'"));
    }

    public function testProgressIsReportedPerStep(): void
    {
        $seen = [];
        (new Migrator($this->db, TestEngine::migrationsPath()))->migrate(
            static function (string $migration, string $step) use (&$seen): void {
                $seen[] = "{$migration}:{$step}";
            }
        );

        self::assertSame('0001_infra:create_kit_kv', $seen[0]);
        self::assertCount(5, $seen);
    }

    // ------------------------------------------------------------ table names

    /**
     * The tracking and lock tables come from KitTables, so a plugin switching
     * over keeps reading the rows its in-tree migrator wrote.
     */
    public function testTheTrackingAndLockTablesAreNamedByKitTables(): void
    {
        $path = $this->makePath();
        $this->writeMigration($path, 'named_0001', 'kit_t_named');

        $tables = new KitTables(migrations: 'kit_x_migrations', locks: 'kit_x_locks', migrationsUnique: 'uq_kit_x_legacy');
        $migrator = new Migrator($this->db, [$path], $tables);
        $migrator->migrate();

        $d = $this->db->dialect();
        self::assertTrue($d->tableExists($this->db->pdo(), 'kit_x_migrations'));
        self::assertTrue($d->tableExists($this->db->pdo(), 'kit_x_locks'));
        self::assertFalse($d->tableExists($this->db->pdo(), 'kit_migrations'), 'the default name is never touched');
        self::assertSame('named_0001', $this->db->fetchValue('SELECT migration FROM kit_x_migrations'));
        self::assertSame(0, (int)$this->db->fetchValue('SELECT COUNT(*) FROM kit_x_locks'), 'the run-lock is released');

        try {
            $this->db->insert('kit_x_migrations', ['migration' => 'named_0001', 'step' => 'create_kit_t_named', 'applied_at' => 1]);
            self::fail('the (migration, step) pair must be unique');
        } catch (\PDOException $e) {
            self::assertTrue($this->db->isUniqueViolation($e));
        }
    }

    // ------------------------------------------------------------- run-lock

    public function testALiveMigrationLockRefusesASecondRun(): void
    {
        $migrator = new Migrator($this->db, TestEngine::migrationsPath());
        $migrator->bootstrap();

        $other = new Lease(TestEngine::sibling(), null, new KitOptions(lockOwner: 'other-host:1'));
        self::assertTrue($other->acquire(Migrator::LOCK_NAME, 600));

        try {
            $migrator->migrate();
            self::fail('a held lock must stop the run');
        } catch (\RuntimeException $e) {
            self::assertStringContainsString('Another migration is already running (kit_locks: migrate)', $e->getMessage());
        }

        $other->release(Migrator::LOCK_NAME);
        self::assertSame(5, $migrator->migrate(), 'released, the run goes ahead');
    }

    public function testAnExpiredMigrationLockIsTakenOver(): void
    {
        $migrator = new Migrator($this->db, TestEngine::migrationsPath());
        $migrator->bootstrap();

        // A process that died mid-migration an hour ago.
        // (run(), not insert(): the locks table has no id for Postgres to return.)
        $this->db->run(
            'INSERT INTO kit_locks (name, locked_by, locked_at, expires_at) VALUES (?, ?, ?, ?)',
            [Migrator::LOCK_NAME, 'dead-host:99', time() - 3600, time() - 3000]
        );

        self::assertSame(5, $migrator->migrate());
        self::assertSame(0, (int)$this->db->fetchValue('SELECT COUNT(*) FROM kit_locks'));
    }

    // ------------------------------------------------------ add-on migrations

    public function testAnAddOnPathAppliesAlongsideTheBaseSchema(): void
    {
        $addon = $this->makePath();
        $this->writeMigration($addon, 'addon_0001_core', 'kit_t_addon_widgets');

        $migrator = new Migrator($this->db, [TestEngine::migrationsPath(), $addon]);
        $migrator->migrate();

        $d = $this->db->dialect();
        self::assertTrue($d->tableExists($this->db->pdo(), 'kit_t_widgets'), 'base schema still applies');
        self::assertTrue($d->tableExists($this->db->pdo(), 'kit_t_addon_widgets'), 'the add-on table is there too');
        self::assertTrue($migrator->isUpToDate());
        self::assertSame(0, $migrator->migrate(), 'a second run across both paths is a no-op');
    }

    public function testPathsRunInTheOrderGivenRatherThanFilenameOrder(): void
    {
        $first = $this->makePath();
        $second = $this->makePath();
        $this->writeMigration($first, 'zzz_last_by_name', 'kit_t_first_path');
        $this->writeMigration($second, 'aaa_first_by_name', 'kit_t_second_path');

        $migrator = new Migrator($this->db, [$first, $second]);

        self::assertSame(
            ['zzz_last_by_name', 'aaa_first_by_name'],
            array_keys($migrator->migrations()),
            'filenames sort within a path; paths themselves keep the order they were given'
        );
    }

    public function testFilesStillSortByNameWithinOnePath(): void
    {
        $path = $this->makePath();
        $this->writeMigration($path, 'b_second', 'kit_t_b');
        $this->writeMigration($path, 'a_first', 'kit_t_a');

        self::assertSame(['a_first', 'b_second'], array_keys((new Migrator($this->db, [$path]))->migrations()));
    }

    public function testASingleStringPathAndATrailingSlashAreAccepted(): void
    {
        $migrator = new Migrator($this->db, TestEngine::migrationsPath() . '/');

        self::assertSame([rtrim(TestEngine::migrationsPath(), '/')], $migrator->paths());
        self::assertArrayHasKey('0001_infra', $migrator->migrations());
    }

    public function testAMigrationNameSeenTwiceAcrossPathsThrows(): void
    {
        $first = $this->makePath();
        $second = $this->makePath();
        $this->writeMigration($first, 'clash_0001', 'kit_t_clash_one');
        $this->writeMigration($second, 'clash_0001', 'kit_t_clash_two');

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/Duplicate migration name .clash_0001./');
        (new Migrator($this->db, [$first, $second]))->migrations();
    }

    public function testAMissingDirectoryContributesNothing(): void
    {
        $migrator = new Migrator(
            $this->db,
            [TestEngine::migrationsPath(), sys_get_temp_dir() . '/gravdbkit-no-such-dir']
        );

        self::assertSame(5, $migrator->migrate(), 'the base schema still applies');
        self::assertTrue($migrator->isUpToDate());
    }

    public function testAFileThatReturnsSomethingElseIsNamedInTheError(): void
    {
        $path = $this->makePath();
        file_put_contents($path . '/0001_bad.php', '<?php return 42;');

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/does not return a Migration instance: .*0001_bad\.php/');
        (new Migrator($this->db, [$path]))->migrations();
    }

    public function testANameThatDoesNotMatchItsFileIsRefused(): void
    {
        $path = $this->makePath();
        $this->writeMigration($path, 'real_name', 'kit_t_x');
        rename($path . '/real_name.php', $path . '/other_name.php');

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage("Migration name 'real_name' does not match filename 'other_name'");
        (new Migrator($this->db, [$path]))->migrations();
    }

    // ------------------------------------------------------------ file cache

    /**
     * Migration files are anonymous classes, and PHP never frees one it has
     * compiled, so reading the same file for every migrator leaks. One file is
     * read once per process: two migrators get the same instance back.
     */
    public function testAMigrationFileIsReadOncePerProcess(): void
    {
        $first = (new Migrator($this->db, TestEngine::migrationsPath()))->migrations();
        $second = (new Migrator(TestEngine::fresh(), TestEngine::migrationsPath()))->migrations();

        self::assertSame($first['0001_infra'], $second['0001_infra']);
    }

    public function testAnEditedFileIsReadAgain(): void
    {
        $path = $this->makePath();
        $this->writeMigration($path, 'edit_0001', 'kit_t_before');
        $before = (new Migrator($this->db, [$path]))->migrations()['edit_0001'];

        $this->writeMigration($path, 'edit_0001', 'kit_t_after_the_edit');
        touch($path . '/edit_0001.php', time() + 5);
        $after = (new Migrator($this->db, [$path]))->migrations()['edit_0001'];

        self::assertNotSame($before, $after);
        self::assertSame(['create_kit_t_after_the_edit'], array_keys($after->steps($this->db->dialect())));

        Migrator::forgetLoadedFiles();
        self::assertNotSame($after, (new Migrator($this->db, [$path]))->migrations()['edit_0001']);
    }

    // ------------------------------------------------------------ fingerprint

    public function testTheFingerprintIsStableForAnUnchangedDirectory(): void
    {
        $dir = $this->tempMigrations(['0001_a.php' => '<?php return 1;']);

        self::assertSame(Migrator::fingerprint([$dir]), Migrator::fingerprint([$dir]));
        self::assertSame(Migrator::fingerprint([$dir]), Migrator::fingerprint([$dir . '/']), 'a trailing slash is the same directory');
    }

    public function testAddingRemovingOrEditingAMigrationChangesTheFingerprint(): void
    {
        $dir = $this->tempMigrations(['0001_a.php' => '<?php return 1;']);
        $original = Migrator::fingerprint([$dir]);

        file_put_contents($dir . '/0002_b.php', '<?php return 2;');
        $added = Migrator::fingerprint([$dir]);
        self::assertNotSame($original, $added);

        unlink($dir . '/0002_b.php');
        self::assertSame($original, Migrator::fingerprint([$dir]), 'removing it again restores the answer');

        // The case a directory mtime would miss: a step appended to a file that already existed.
        file_put_contents($dir . '/0001_a.php', '<?php return 1; // a second step');
        touch($dir . '/0001_a.php', time() + 10);
        self::assertNotSame($original, Migrator::fingerprint([$dir]));
    }

    public function testAnAddOnDirectoryJoiningTheSetChangesTheFingerprint(): void
    {
        $base = $this->tempMigrations(['0001_a.php' => '<?php return 1;']);
        $addon = $this->tempMigrations(['9001_addon.php' => '<?php return 9;']);

        self::assertNotSame(Migrator::fingerprint([$base]), Migrator::fingerprint([$base, $addon]));
    }

    public function testADirectoryThatDoesNotExistFingerprintsAsEmpty(): void
    {
        self::assertSame(
            Migrator::fingerprint([]),
            Migrator::fingerprint([sys_get_temp_dir() . '/gravdbkit-nothing-here-' . uniqid('', true)])
        );
    }

    // ------------------------------------------------------------- helpers

    /** A scratch directory that tearDown() cleans up. */
    private function makePath(): string
    {
        $path = sys_get_temp_dir() . '/gravdbkit-migrations-' . bin2hex(random_bytes(6));
        mkdir($path, 0o777, true);
        $this->tempPaths[] = $path;

        return $path;
    }

    /**
     * @param array<string, string> $files
     */
    private function tempMigrations(array $files): string
    {
        $dir = $this->makePath();
        foreach ($files as $name => $contents) {
            file_put_contents($dir . '/' . $name, $contents);
        }

        return $dir;
    }

    /** A migration file whose single step creates one empty table. */
    private function writeMigration(string $path, string $name, string $table): void
    {
        $template = <<<'PHP'
        <?php

        declare(strict_types=1);

        use TrilbyMedia\GravDbKit\Database\Connection;
        use TrilbyMedia\GravDbKit\Database\Dialect\Dialect;
        use TrilbyMedia\GravDbKit\Database\Migration;

        return new class implements Migration {
            public function name(): string
            {
                return '__NAME__';
            }

            public function steps(Dialect $d): array
            {
                return [
                    'create___TABLE__' => function (Connection $c) use ($d): void {
                        if (!$d->tableExists($c->pdo(), '__TABLE__')) {
                            $c->run("CREATE TABLE __TABLE__ (id {$d->primaryKey()}) {$d->tableOptions()}");
                        }
                    },
                ];
            }
        };

        PHP;

        file_put_contents(
            $path . '/' . $name . '.php',
            strtr($template, ['__NAME__' => $name, '__TABLE__' => $table])
        );
    }
}
