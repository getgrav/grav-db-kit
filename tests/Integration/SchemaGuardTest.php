<?php

declare(strict_types=1);

namespace TrilbyMedia\GravDbKit\Tests\Integration;

use PHPUnit\Framework\TestCase;
use TrilbyMedia\GravDbKit\Database\CallbackSchemaState;
use TrilbyMedia\GravDbKit\Database\Connection;
use TrilbyMedia\GravDbKit\Database\KvSchemaState;
use TrilbyMedia\GravDbKit\Database\Migrator;
use TrilbyMedia\GravDbKit\Database\SchemaGuard;
use TrilbyMedia\GravDbKit\Support\KvStore;
use TrilbyMedia\GravDbKit\Tests\Support\TestEngine;

final class SchemaGuardTest extends TestCase
{
    private Connection $db;

    protected function setUp(): void
    {
        $this->db = TestEngine::fresh();
    }

    public function testAutoMigratesAFreshDatabaseAndRemembersTheFingerprint(): void
    {
        $migrator = $this->migrator();

        $status = SchemaGuard::ensure($migrator, $this->kvState(), 'auto');

        self::assertSame(['policy' => 'auto', 'pending' => 0, 'applied' => 5], $status);
        self::assertSame(
            Migrator::fingerprint($migrator->paths()),
            (new KvStore($this->db))->get('migrations.complete')
        );
    }

    /**
     * Once the fingerprint matches, nothing else is asked: a tracking row
     * deleted behind the guard's back goes unnoticed, which is the proof the
     * whole check was skipped.
     */
    public function testAMatchingFingerprintSkipsTheCheck(): void
    {
        SchemaGuard::ensure($this->migrator(), $this->kvState(), 'auto');
        $this->db->execute('DELETE FROM kit_migrations WHERE step = ?', ['seed_kit_t_widgets']);

        self::assertSame(
            ['policy' => 'auto', 'pending' => 0, 'applied' => 0],
            SchemaGuard::ensure($this->migrator(), $this->kvState(), 'auto')
        );
    }

    public function testAChangedMigrationSetRunsTheCheckAgain(): void
    {
        SchemaGuard::ensure($this->migrator(), $this->kvState(), 'auto');

        $addon = sys_get_temp_dir() . '/gravdbkit-guard-' . bin2hex(random_bytes(6));
        mkdir($addon);
        file_put_contents($addon . '/addon_0001.php', <<<'PHP'
            <?php
            use TrilbyMedia\GravDbKit\Database\Connection;
            use TrilbyMedia\GravDbKit\Database\Dialect\Dialect;
            use TrilbyMedia\GravDbKit\Database\Migration;

            return new class implements Migration {
                public function name(): string { return 'addon_0001'; }
                public function steps(Dialect $d): array
                {
                    return ['noop' => static function (Connection $c): void {}];
                }
            };
            PHP);

        try {
            $migrator = new Migrator($this->db, [TestEngine::migrationsPath(), $addon]);
            self::assertSame(
                ['policy' => 'sqlite-only', 'pending' => $this->engineIs('sqlite') ? 0 : 1, 'applied' => $this->engineIs('sqlite') ? 1 : 0],
                SchemaGuard::ensure($migrator, $this->kvState(), 'sqlite-only')
            );
        } finally {
            unlink($addon . '/addon_0001.php');
            rmdir($addon);
        }
    }

    /** Manual reports and never applies, and remembers nothing until the CLI has done its work. */
    public function testManualOnlyReports(): void
    {
        $remembered = [];
        $state = new CallbackSchemaState(
            static fn (): ?string => null,
            static function (string $fingerprint) use (&$remembered): void {
                $remembered[] = $fingerprint;
            }
        );

        self::assertSame(['policy' => 'manual', 'pending' => 5, 'applied' => 0], SchemaGuard::ensure($this->migrator(), $state, 'manual'));
        self::assertFalse($this->db->dialect()->tableExists($this->db->pdo(), 'kit_t_widgets'));
        self::assertSame([], $remembered);

        $this->migrator()->migrate(); // the CLI run

        self::assertSame(['policy' => 'manual', 'pending' => 0, 'applied' => 0], SchemaGuard::ensure($this->migrator(), $state, 'manual'));
        self::assertSame([Migrator::fingerprint($this->migrator()->paths())], $remembered);
    }

    /** `sqlite` migrates the zero-admin default and leaves server databases to the CLI. */
    public function testTheSqlitePolicyDependsOnTheEngine(): void
    {
        $status = SchemaGuard::ensure($this->migrator(), $this->kvState(), 'sqlite');

        if ($this->engineIs('sqlite')) {
            self::assertSame(['policy' => 'sqlite', 'pending' => 0, 'applied' => 5], $status);
        } else {
            self::assertSame(['policy' => 'sqlite', 'pending' => 5, 'applied' => 0], $status);
        }
    }

    public function testACallbackStoreThatThrowsReadsAsNotRecorded(): void
    {
        $state = new CallbackSchemaState(
            static function (): never {
                throw new \RuntimeException('cache backend down');
            },
            static function (): void {
            }
        );

        self::assertNull($state->remembered());
        self::assertSame(5, SchemaGuard::ensure($this->migrator(), $state, 'auto')['applied']);
    }

    public function testACallbackStoreAnsweringFalseOrEmptyReadsAsNotRecorded(): void
    {
        self::assertNull((new CallbackSchemaState(static fn (): bool => false, static function (): void {}))->remembered());
        self::assertNull((new CallbackSchemaState(static fn (): string => '', static function (): void {}))->remembered());
        self::assertSame('abc', (new CallbackSchemaState(static fn (): string => 'abc', static function (): void {}))->remembered());
    }

    public function testTheKvStateReadsNothingFromADatabaseWithoutItsTable(): void
    {
        self::assertNull($this->kvState()->remembered());
    }

    public function testAnUnknownPolicyTouchesNothing(): void
    {
        try {
            SchemaGuard::ensure($this->migrator(), $this->kvState(), 'always');
            self::fail('expected the policy to be refused');
        } catch (\InvalidArgumentException) {
        }

        self::assertFalse($this->db->dialect()->tableExists($this->db->pdo(), 'kit_migrations'));
    }

    private function migrator(): Migrator
    {
        return new Migrator($this->db, TestEngine::migrationsPath());
    }

    private function kvState(): KvSchemaState
    {
        return new KvSchemaState(new KvStore($this->db));
    }

    private function engineIs(string $engine): bool
    {
        return $this->db->dialect()->name() === $engine;
    }
}
