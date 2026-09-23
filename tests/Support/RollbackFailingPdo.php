<?php

declare(strict_types=1);

namespace TrilbyMedia\GravDbKit\Tests\Support;

/**
 * SQLite that refuses to roll back to a savepoint, the way MySQL and MariaDB do after a deadlock.
 *
 * A deadlock rolls the whole transaction back before the client hears about it, so every savepoint taken inside it is gone and `ROLLBACK TO SAVEPOINT` answers error 1305. SQLite never behaves that way, so the refusal is faked here to keep the test in memory.
 */
final class RollbackFailingPdo extends \PDO
{
    /** @var list<string> every statement handed to exec(), in order */
    public array $executed = [];

    public function __construct()
    {
        parent::__construct('sqlite::memory:', null, null, [
            \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION,
            \PDO::ATTR_DEFAULT_FETCH_MODE => \PDO::FETCH_ASSOC,
        ]);
    }

    public function exec(string $statement): int|false
    {
        $this->executed[] = $statement;

        if (str_starts_with($statement, 'ROLLBACK TO SAVEPOINT')) {
            $e = new \PDOException('SQLSTATE[HY000]: General error: 1305 SAVEPOINT sp_1 does not exist');
            $e->errorInfo = ['HY000', 1305, 'SAVEPOINT sp_1 does not exist'];

            throw $e;
        }

        return parent::exec($statement);
    }
}
