<?php

declare(strict_types=1);

namespace TrilbyMedia\GravDbKit\Tests\Unit;

use TrilbyMedia\GravDbKit\Database\Connection;
use TrilbyMedia\GravDbKit\Database\Dialect\MysqlDialect;
use TrilbyMedia\GravDbKit\Database\Dialect\PgsqlDialect;
use TrilbyMedia\GravDbKit\Database\Dialect\SqliteDialect;
use PHPUnit\Framework\TestCase;

/**
 * Telling "this row is already there" apart from every other database error.
 *
 * Code that inserts a row and falls back to reading it depends on this answer. Get it wrong on MySQL or PostgreSQL and a deadlock is mistaken for a duplicate, the fallback read finds nothing, and the transaction that should have been retried reports a bogus failure instead.
 */
final class DialectUniqueViolationTest extends TestCase
{
    /**
     * SQLite reports every constraint failure as SQLITE_CONSTRAINT (19) under SQLSTATE 23000, so these cases run against a real in-memory database rather than a hand-built exception: only the message separates a duplicate from a NOT NULL failure or a trigger's abort.
     */
    public function testSqliteSeparatesADuplicateFromEveryOtherConstraint(): void
    {
        $dialect = new SqliteDialect();
        $pdo = new \PDO('sqlite::memory:', null, null, [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION]);
        $pdo->exec('CREATE TABLE widgets (id INTEGER PRIMARY KEY, sku TEXT UNIQUE, name TEXT NOT NULL)');
        $pdo->exec("CREATE TRIGGER widgets_guard BEFORE INSERT ON widgets WHEN NEW.sku = 'blocked' BEGIN SELECT RAISE(ABORT, 'that sku is not allowed'); END");
        $pdo->exec("INSERT INTO widgets (id, sku, name) VALUES (1, 'a', 'first')");

        self::assertTrue(
            $dialect->isUniqueViolation($this->failedInsert($pdo, "INSERT INTO widgets (sku, name) VALUES ('a', 'second')")),
            'a duplicate unique key is a unique violation'
        );
        self::assertTrue(
            $dialect->isUniqueViolation($this->failedInsert($pdo, "INSERT INTO widgets (id, sku, name) VALUES (1, 'b', 'second')")),
            'a duplicate primary key is a unique violation'
        );
        self::assertFalse(
            $dialect->isUniqueViolation($this->failedInsert($pdo, "INSERT INTO widgets (sku, name) VALUES ('c', NULL)")),
            'a NOT NULL failure is not a duplicate'
        );
        self::assertFalse(
            $dialect->isUniqueViolation($this->failedInsert($pdo, "INSERT INTO widgets (sku, name) VALUES ('blocked', 'second')")),
            "a trigger's abort is not a duplicate"
        );
        self::assertFalse(
            $dialect->isUniqueViolation($this->pdoException('HY000', 5, 'database is locked')),
            'a busy database is not a duplicate'
        );
    }

    /** Some pdo_sqlite builds file the constraint under HY000 rather than 23000; the message is what decides either way. */
    public function testSqliteAcceptsTheHy000FormAndOlderWordings(): void
    {
        $dialect = new SqliteDialect();

        self::assertTrue($dialect->isUniqueViolation($this->pdoException('HY000', 19, 'UNIQUE constraint failed: widgets.sku')));
        self::assertTrue($dialect->isUniqueViolation($this->pdoException('23000', 19, 'PRIMARY KEY constraint failed: widgets.id')));
        self::assertTrue($dialect->isUniqueViolation($this->pdoException('23000', 19, 'column sku is not unique')));
        self::assertFalse($dialect->isUniqueViolation($this->pdoException('HY000', 19, 'FOREIGN KEY constraint failed')));
    }

    public function testMysqlOnlyCountsTheDuplicateKeyNumbers(): void
    {
        $dialect = new MysqlDialect();

        self::assertTrue($dialect->isUniqueViolation($this->pdoException('23000', 1062, "Duplicate entry 'a' for key 'widgets.sku'")));
        self::assertTrue($dialect->isUniqueViolation($this->pdoException('23000', 1586, "Duplicate entry 'a' for key 'sku'")));
        self::assertFalse($dialect->isUniqueViolation($this->pdoException('23000', 1452, 'Cannot add or update a child row')));
        self::assertFalse(
            $dialect->isUniqueViolation($this->pdoException('40001', 1213, 'Deadlock found when trying to get lock')),
            'a deadlock must stay retryable rather than read as a duplicate'
        );
        self::assertFalse($dialect->isUniqueViolation($this->pdoException('23505', 1062, 'wrong engine')));
    }

    public function testPostgresUsesItsOwnStateForADuplicate(): void
    {
        $dialect = new PgsqlDialect();

        self::assertTrue($dialect->isUniqueViolation($this->pdoException('23505', 7, 'duplicate key value violates unique constraint "widgets_sku_key"')));
        self::assertFalse($dialect->isUniqueViolation($this->pdoException('23503', 7, 'violates foreign key constraint')));
        self::assertFalse($dialect->isUniqueViolation($this->pdoException('23502', 7, 'null value in column "name"')));
        self::assertFalse(
            $dialect->isUniqueViolation($this->pdoException('40P01', 7, 'deadlock detected')),
            'a deadlock must stay retryable rather than read as a duplicate'
        );
        self::assertFalse($dialect->isUniqueViolation($this->pdoException('40001', 7, 'could not serialize access')));
    }

    /** The connection hands the question to its dialect so add-ons never have to hold the engine table themselves. */
    public function testTheConnectionAnswersTheSameAsItsDialect(): void
    {
        $connection = new Connection(new \PDO('sqlite::memory:'), new SqliteDialect());

        self::assertTrue($connection->isUniqueViolation($this->pdoException('23000', 19, 'UNIQUE constraint failed: widgets.sku')));
        self::assertFalse($connection->isUniqueViolation($this->pdoException('23000', 19, 'NOT NULL constraint failed: widgets.name')));
    }

    private function failedInsert(\PDO $pdo, string $sql): \PDOException
    {
        try {
            $pdo->exec($sql);
        } catch (\PDOException $e) {
            return $e;
        }

        self::fail('expected ' . $sql . ' to fail');
    }

    private function pdoException(string $state, int $driverCode, string $message): \PDOException
    {
        $e = new \PDOException(sprintf('SQLSTATE[%s]: %d %s', $state, $driverCode, $message));
        $e->errorInfo = [$state, $driverCode, $message];

        // PDO reports the SQLSTATE as the exception code, which is a string and therefore only reachable through reflection.
        $code = new \ReflectionProperty(\Exception::class, 'code');
        $code->setValue($e, $state);

        return $e;
    }
}
