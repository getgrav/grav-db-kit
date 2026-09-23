<?php

declare(strict_types=1);

namespace TrilbyMedia\GravDbKit\Tests\Unit;

use TrilbyMedia\GravDbKit\Database\Connection;
use TrilbyMedia\GravDbKit\Database\Dialect\SqliteDialect;
use TrilbyMedia\GravDbKit\Database\KitOptions;
use TrilbyMedia\GravDbKit\Tests\Support\RollbackFailingPdo;
use PHPUnit\Framework\TestCase;

/**
 * What a nested transaction does when the engine has thrown the savepoint away underneath it.
 *
 * MySQL and MariaDB undo the whole transaction the moment they pick a deadlock victim, so the client's `ROLLBACK TO SAVEPOINT` is answered with "SAVEPOINT sp_1 does not exist". If that second failure is the one that leaves the nested call, the outer transaction is handed an error it cannot recognise and gives up on work it was meant to retry.
 */
final class ConnectionTransactionTest extends TestCase
{
    public function testAFailedSavepointRollbackDoesNotReplaceTheOriginalError(): void
    {
        $connection = $this->connection();
        $depthInsideOuter = null;
        $depthAfterNested = null;

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('stock went negative');

        try {
            $connection->transaction(
                function (Connection $c) use (&$depthInsideOuter, &$depthAfterNested): void {
                    $depthInsideOuter = $this->depthOf($c);

                    try {
                        $c->transaction(static function (): void {
                            throw new \RuntimeException('stock went negative');
                        });
                    } catch (\RuntimeException $e) {
                        $depthAfterNested = $this->depthOf($c);

                        throw $e;
                    }
                }
            );
        } finally {
            self::assertSame(1, $depthInsideOuter);
            self::assertSame(1, $depthAfterNested, 'the nested call must give the depth back it took');
            self::assertSame(0, $this->depthOf($connection));
        }
    }

    /**
     * A deadlock raised inside nested work is what the outer retry loop sees, so the whole transaction runs again rather than reporting a missing savepoint to the caller.
     */
    public function testARetryableErrorInsideANestedTransactionStillReachesTheRetryLoop(): void
    {
        $connection = $this->connection();
        $attempts = 0;

        $result = $connection->transaction(function (Connection $c) use (&$attempts): string {
            $attempts++;

            return $c->transaction(static function (Connection $c) use ($attempts): string {
                $c->execute('INSERT INTO widgets (name) VALUES (?)', ['first try']);

                if ($attempts === 1) {
                    $e = new \PDOException('SQLSTATE[HY000]: General error: 5 database is locked');
                    $e->errorInfo = ['HY000', 5, 'database is locked'];

                    throw $e;
                }

                return 'committed';
            });
        });

        self::assertSame('committed', $result);
        self::assertSame(2, $attempts);
        self::assertSame(1, (int)$connection->fetchValue('SELECT COUNT(*) FROM widgets'));
        self::assertSame(0, $this->depthOf($connection));
    }

    /**
     * A plugin switching to the kit keeps the savepoint names its error messages always had.
     */
    public function testSavepointsAreNamedFromTheConfiguredPrefix(): void
    {
        $pdo = new RollbackFailingPdo();
        $connection = new Connection($pdo, new SqliteDialect(), new KitOptions(savepointPrefix: 'fp_sp_'));

        $connection->transaction(static function (Connection $c): void {
            $c->transaction(static function (Connection $c): void {
                $c->transaction(static function (): void {
                });
            });
        });

        self::assertSame(
            ['SAVEPOINT fp_sp_1', 'SAVEPOINT fp_sp_2', 'RELEASE SAVEPOINT fp_sp_2', 'RELEASE SAVEPOINT fp_sp_1'],
            $pdo->executed
        );
        self::assertFalse($connection->inTransaction());
    }

    public function testTheDefaultSavepointPrefixIsSp(): void
    {
        $pdo = new RollbackFailingPdo();
        $connection = new Connection($pdo, new SqliteDialect());

        $connection->transaction(static function (Connection $c): void {
            self::assertTrue($c->inTransaction());
            $c->transaction(static function (): void {
            });
        });

        self::assertSame(['SAVEPOINT sp_1', 'RELEASE SAVEPOINT sp_1'], $pdo->executed);
    }

    private function connection(): Connection
    {
        $connection = new Connection(new RollbackFailingPdo(), new SqliteDialect());
        $connection->run('CREATE TABLE widgets (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT NOT NULL)');

        return $connection;
    }

    private function depthOf(Connection $connection): int
    {
        $property = new \ReflectionProperty(Connection::class, 'transactionDepth');

        return (int)$property->getValue($connection);
    }
}
