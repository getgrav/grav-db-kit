<?php

declare(strict_types=1);

namespace TrilbyMedia\GravDbKit\Tests\Integration;

use PHPUnit\Framework\TestCase;
use TrilbyMedia\GravDbKit\Database\Connection;
use TrilbyMedia\GravDbKit\Tests\Support\TestEngine;

/**
 * The write paths that depend on engine-specific SQL (insert-returning-id,
 * upsert, bulk insert with supplied keys, identity sequences, index and
 * column probes, savepoints) behave the same on whichever engine this run
 * selected.
 */
final class ConnectionTest extends TestCase
{
    private Connection $db;

    protected function setUp(): void
    {
        $this->db = TestEngine::fresh();
        $d = $this->db->dialect();
        $this->db->run("CREATE TABLE kit_t_items (
            id {$d->primaryKey()},
            sku VARCHAR(64) NOT NULL,
            qty INTEGER NOT NULL DEFAULT 0,
            note {$d->textLong()} NULL,
            CONSTRAINT uq_kit_t_items_sku UNIQUE (sku)
        ) {$d->tableOptions()}");
    }

    public function testInsertReturnsTheGeneratedIdAndRowsReadBack(): void
    {
        $first = $this->db->insert('kit_t_items', ['sku' => 'a', 'qty' => 1]);
        $second = $this->db->insert('kit_t_items', ['sku' => 'b', 'qty' => 2]);

        self::assertGreaterThan(0, $first);
        self::assertSame($first + 1, $second);
        self::assertSame('b', $this->db->fetchValue('SELECT sku FROM kit_t_items WHERE id = ?', [$second]));
        self::assertSame(2, (int)$this->db->fetchRow('SELECT qty FROM kit_t_items WHERE sku = ?', ['b'])['qty']);
        self::assertCount(2, $this->db->fetchAll('SELECT * FROM kit_t_items ORDER BY id'));
        self::assertNull($this->db->fetchRow('SELECT * FROM kit_t_items WHERE sku = ?', ['missing']));
        self::assertNull($this->db->fetchValue('SELECT qty FROM kit_t_items WHERE sku = ?', ['missing']));
    }

    public function testUpdateAndDeleteReportAffectedRows(): void
    {
        $this->db->insert('kit_t_items', ['sku' => 'a', 'qty' => 1]);
        $this->db->insert('kit_t_items', ['sku' => 'b', 'qty' => 1]);

        self::assertSame(2, $this->db->update('kit_t_items', ['qty' => 5], 'qty = ?', [1]));
        self::assertSame(1, $this->db->delete('kit_t_items', 'sku = ?', ['a']));
        self::assertSame(5, (int)$this->db->fetchValue('SELECT qty FROM kit_t_items WHERE sku = ?', ['b']));
    }

    public function testUpsertUpdatesOnConflictAndInsertIgnoresWithNoUpdateColumns(): void
    {
        $this->db->upsert('kit_t_items', ['sku' => 'a', 'qty' => 1], ['sku'], ['qty']);
        $this->db->upsert('kit_t_items', ['sku' => 'a', 'qty' => 9], ['sku'], ['qty']);
        self::assertSame(9, (int)$this->db->fetchValue('SELECT qty FROM kit_t_items WHERE sku = ?', ['a']));

        $this->db->upsert('kit_t_items', ['sku' => 'a', 'qty' => 100], ['sku'], []);
        self::assertSame(9, (int)$this->db->fetchValue('SELECT qty FROM kit_t_items WHERE sku = ?', ['a']), 'insert-ignore leaves the row alone');
        self::assertSame(1, (int)$this->db->fetchValue('SELECT COUNT(*) FROM kit_t_items'));
    }

    public function testADuplicateIsAUniqueViolationOnEveryEngine(): void
    {
        $this->db->insert('kit_t_items', ['sku' => 'a']);

        try {
            $this->db->insert('kit_t_items', ['sku' => 'a']);
            self::fail('the second insert should have failed');
        } catch (\PDOException $e) {
            self::assertTrue($this->db->isUniqueViolation($e));
        }

        try {
            $this->db->run('INSERT INTO kit_t_items (sku, qty) VALUES (?, ?)', [null, 1]);
            self::fail('a NULL sku should have failed');
        } catch (\PDOException $e) {
            self::assertFalse($this->db->isUniqueViolation($e), 'a NOT NULL failure is not a duplicate');
        }
    }

    public function testInsertManyWithSuppliedIdsThenSyncTheSequence(): void
    {
        $rows = [];
        for ($i = 1; $i <= 250; $i++) {
            $rows[] = [$i * 10, 'sku-' . $i, $i];
        }

        $this->db->insertMany('kit_t_items', ['id', 'sku', 'qty'], $rows);
        $this->db->syncIdentitySequence('kit_t_items');

        self::assertSame(250, (int)$this->db->fetchValue('SELECT COUNT(*) FROM kit_t_items'));
        self::assertSame('sku-7', $this->db->fetchValue('SELECT sku FROM kit_t_items WHERE id = ?', [70]));

        // The first ordinary insert after a bulk load must not collide with a loaded key.
        $next = $this->db->insert('kit_t_items', ['sku' => 'after']);
        self::assertGreaterThan(2500, $next);

        $this->db->insertMany('kit_t_items', ['id', 'sku'], []);
        self::assertSame(251, (int)$this->db->fetchValue('SELECT COUNT(*) FROM kit_t_items'), 'no rows is a no-op');
    }

    public function testTableColumnAndIndexProbes(): void
    {
        $d = $this->db->dialect();
        $pdo = $this->db->pdo();

        self::assertTrue($d->tableExists($pdo, 'kit_t_items'));
        self::assertFalse($d->tableExists($pdo, 'kit_t_nothing'));
        self::assertTrue($d->columnExists($pdo, 'kit_t_items', 'qty'));
        self::assertFalse($d->columnExists($pdo, 'kit_t_items', 'colour'));

        $d->createIndexIfMissing($pdo, 'kit_t_items', 'ix_kit_t_items_qty', ['qty']);
        $d->createIndexIfMissing($pdo, 'kit_t_items', 'ix_kit_t_items_qty', ['qty']);
        $d->dropIndexIfExists($pdo, 'kit_t_items', 'ix_kit_t_items_qty');
        $d->dropIndexIfExists($pdo, 'kit_t_items', 'ix_kit_t_items_qty');

        self::assertTrue($d->renameTableIfExists($pdo, 'kit_t_items', 'kit_t_renamed'));
        self::assertFalse($d->renameTableIfExists($pdo, 'kit_t_items', 'kit_t_renamed'), 'a repeat is a no-op');
        self::assertTrue($d->tableExists($pdo, 'kit_t_renamed'));
    }

    public function testATransactionCommitsOrRollsBackAsAWhole(): void
    {
        $this->db->transaction(static function (Connection $c): void {
            $c->insert('kit_t_items', ['sku' => 'kept']);
        });

        try {
            $this->db->transaction(static function (Connection $c): void {
                $c->insert('kit_t_items', ['sku' => 'lost']);
                throw new \RuntimeException('abort');
            });
        } catch (\RuntimeException) {
        }

        self::assertSame(['kept'], array_column($this->db->fetchAll('SELECT sku FROM kit_t_items'), 'sku'));
    }

    /** A nested call that fails undoes only its own work; the outer transaction carries on and commits. */
    public function testANestedFailureRollsBackToItsSavepointOnly(): void
    {
        $result = $this->db->transaction(function (Connection $c): string {
            $c->insert('kit_t_items', ['sku' => 'outer']);

            try {
                $c->transaction(static function (Connection $c): void {
                    $c->insert('kit_t_items', ['sku' => 'inner']);
                    throw new \RuntimeException('inner failed');
                });
            } catch (\RuntimeException) {
            }

            $c->transaction(static function (Connection $c): void {
                $c->insert('kit_t_items', ['sku' => 'second inner']);
            });

            return 'done';
        });

        self::assertSame('done', $result);
        self::assertSame(
            ['outer', 'second inner'],
            array_column($this->db->fetchAll('SELECT sku FROM kit_t_items ORDER BY id'), 'sku')
        );
        self::assertFalse($this->db->inTransaction());
    }

    public function testRetryableErrorsRetryTheWholeTransaction(): void
    {
        $attempts = 0;
        $this->db->transaction(function (Connection $c) use (&$attempts): void {
            $attempts++;
            $c->insert('kit_t_items', ['sku' => 'x' . $attempts]);
            if ($attempts < 3) {
                throw $this->retryable($c);
            }
        });

        self::assertSame(3, $attempts);
        self::assertSame(['x3'], array_column($this->db->fetchAll('SELECT sku FROM kit_t_items'), 'sku'));
    }

    public function testRetriesGiveUpAfterThreeAttempts(): void
    {
        $attempts = 0;

        try {
            $this->db->transaction(function (Connection $c) use (&$attempts): void {
                $attempts++;
                throw $this->retryable($c);
            });
            self::fail('expected the busy error to escape');
        } catch (\PDOException) {
        }

        self::assertSame(3, $attempts);
        self::assertFalse($this->db->inTransaction());
    }

    /** An error each engine's dialect files as retryable (busy, deadlock, serialization failure). */
    private function retryable(Connection $c): \PDOException
    {
        [$state, $code, $message] = match ($c->dialect()->name()) {
            'mysql' => ['40001', 1213, 'Deadlock found when trying to get lock'],
            'pgsql' => ['40P01', 7, 'deadlock detected'],
            default => ['HY000', 5, 'database is locked'],
        };

        $e = new \PDOException("SQLSTATE[{$state}]: {$message}");
        $e->errorInfo = [$state, $code, $message];
        self::assertTrue($c->dialect()->isRetryableError($e));

        return $e;
    }
}
