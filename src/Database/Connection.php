<?php

declare(strict_types=1);

namespace TrilbyMedia\GravDbKit\Database;

use TrilbyMedia\GravDbKit\Database\Dialect\Dialect;

/**
 * Thin PDO wrapper. All runtime SQL written against this class must stay in
 * the portable subset shared by SQLite, MySQL 8+/MariaDB 10.6+, Postgres 14+:
 *
 *  - identifiers unquoted (validated names) or via Dialect::quoteIdentifier();
 *    never backticks or double quotes inline
 *  - all values bound as parameters; no inline string literals for data
 *  - LIMIT/OFFSET on SELECT only (not UPDATE/DELETE — Postgres lacks it)
 *  - no RETURNING / ON CONFLICT / ON DUPLICATE outside Dialect methods
 *  - timestamps as UTC unix-epoch BIGINT; booleans as INTEGER 0/1
 *
 * Nested transaction() calls become savepoints named from
 * KitOptions::$savepointPrefix, so a plugin that switches to the kit keeps the
 * savepoint names its error messages always had.
 */
final class Connection
{
    private const MAX_RETRIES = 3;

    private int $transactionDepth = 0;

    private readonly KitOptions $options;

    public function __construct(
        private readonly \PDO $pdo,
        private readonly Dialect $dialect,
        ?KitOptions $options = null,
    ) {
        $this->options = $options ?? new KitOptions();
    }

    public function pdo(): \PDO
    {
        return $this->pdo;
    }

    public function dialect(): Dialect
    {
        return $this->dialect;
    }

    public function options(): KitOptions
    {
        return $this->options;
    }

    /** Whether a transaction() call is running on this connection right now. */
    public function inTransaction(): bool
    {
        return $this->transactionDepth > 0;
    }

    /**
     * @param list<mixed> $params
     */
    public function run(string $sql, array $params = []): \PDOStatement
    {
        if ($params === []) {
            $stmt = $this->pdo->query($sql);
            if ($stmt === false) {
                throw new \RuntimeException("Query failed: {$sql}");
            }

            return $stmt;
        }

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);

        return $stmt;
    }

    /**
     * @param list<mixed> $params
     * @return list<array<string, mixed>>
     */
    public function fetchAll(string $sql, array $params = []): array
    {
        return $this->run($sql, $params)->fetchAll();
    }

    /**
     * @param list<mixed> $params
     * @return array<string, mixed>|null
     */
    public function fetchRow(string $sql, array $params = []): ?array
    {
        $row = $this->run($sql, $params)->fetch();

        return $row === false ? null : $row;
    }

    /**
     * @param list<mixed> $params
     */
    public function fetchValue(string $sql, array $params = []): mixed
    {
        $value = $this->run($sql, $params)->fetchColumn();

        return $value === false ? null : $value;
    }

    /**
     * @param list<mixed> $params
     * @return int affected row count
     */
    public function execute(string $sql, array $params = []): int
    {
        return $this->run($sql, $params)->rowCount();
    }

    /**
     * @param array<string, mixed> $row
     * @return int generated id
     */
    public function insert(string $table, array $row): int
    {
        return $this->dialect->insertReturningId($this->pdo, $table, $row);
    }

    /**
     * Bulk-load rows whose ids are already decided.
     *
     * Written for bulk seeders and importers, which know every id before they
     * write anything because the child rows have to point back at them.
     * Ordinary code inserts one row at a time through insert() and lets the
     * engine choose the key.
     *
     * @param string[] $columns
     * @param list<list<mixed>> $rows one value per column, in $columns order
     */
    public function insertMany(string $table, array $columns, array $rows): void
    {
        $this->dialect->insertMany($this->pdo, $table, $columns, $rows);
    }

    /** Put a bulk-loaded table's identity sequence past its largest id. */
    public function syncIdentitySequence(string $table, string $column = 'id'): void
    {
        $this->dialect->syncIdentitySequence($this->pdo, $table, $column);
    }

    /**
     * @param array<string, mixed> $row
     * @param string[] $conflictColumns
     * @param string[] $updateColumns
     */
    public function upsert(string $table, array $row, array $conflictColumns, array $updateColumns): void
    {
        $this->dialect->upsert($this->pdo, $table, $row, $conflictColumns, $updateColumns);
    }

    /**
     * @param array<string, mixed> $values
     * @param list<mixed> $whereParams
     */
    public function update(string $table, array $values, string $where, array $whereParams = []): int
    {
        $sets = [];
        foreach (array_keys($values) as $column) {
            $sets[] = $this->dialect->quoteIdentifier($column) . ' = ?';
        }

        $sql = sprintf(
            'UPDATE %s SET %s WHERE %s',
            $this->dialect->quoteIdentifier($table),
            implode(', ', $sets),
            $where
        );

        return $this->execute($sql, [...array_values($values), ...$whereParams]);
    }

    /**
     * @param list<mixed> $params
     */
    public function delete(string $table, string $where, array $params = []): int
    {
        $sql = sprintf('DELETE FROM %s WHERE %s', $this->dialect->quoteIdentifier($table), $where);

        return $this->execute($sql, $params);
    }

    /**
     * Whether a database error is the engine saying the row is already there.
     *
     * Code that inserts a row it expects to be there already asks this before it treats a failure as a duplicate. Without it a deadlock or a serialization failure reads as "already exists", the row is fetched back as null, and the retry transaction() was going to run never happens. The three engines report a duplicate differently; the dialect knows which.
     */
    public function isUniqueViolation(\PDOException $e): bool
    {
        return $this->dialect->isUniqueViolation($e);
    }

    /**
     * Run $fn atomically. Nested calls use savepoints. The outermost
     * transaction retries on transient conflicts (SQLite busy, deadlocks) —
     * $fn must therefore be safe to re-execute from the top.
     *
     * @template T
     * @param callable(self): T $fn
     * @return T
     */
    public function transaction(callable $fn): mixed
    {
        if ($this->transactionDepth > 0) {
            return $this->savepointTransaction($fn);
        }

        $attempt = 0;
        while (true) {
            $attempt++;
            $this->pdo->beginTransaction();
            $this->transactionDepth = 1;

            try {
                $result = $fn($this);
                $this->pdo->commit();
                $this->transactionDepth = 0;

                return $result;
            } catch (\Throwable $e) {
                $this->transactionDepth = 0;
                if ($this->pdo->inTransaction()) {
                    $this->pdo->rollBack();
                }

                $retryable = $e instanceof \PDOException && $this->dialect->isRetryableError($e);
                if (!$retryable || $attempt >= self::MAX_RETRIES) {
                    throw $e;
                }

                usleep(random_int(20_000, 50_000) * $attempt);
            }
        }
    }

    /**
     * Nested call: the work runs inside a savepoint of the transaction already open.
     *
     * Whatever $fn threw is what leaves this method, even when rolling back to the savepoint fails as well. A deadlock on MySQL and MariaDB has already rolled the whole transaction back and thrown every savepoint away by the time we get here, so the rollback answers "SAVEPOINT sp_1 does not exist"; letting that replace the deadlock would hide the one error the outer transaction() knows how to retry.
     *
     * @template T
     * @param callable(self): T $fn
     * @return T
     */
    private function savepointTransaction(callable $fn): mixed
    {
        $savepoint = $this->options->savepointPrefix . $this->transactionDepth;
        $this->pdo->exec("SAVEPOINT {$savepoint}");
        $this->transactionDepth++;

        try {
            $result = $fn($this);
            $this->pdo->exec("RELEASE SAVEPOINT {$savepoint}");

            return $result;
        } catch (\Throwable $e) {
            try {
                $this->pdo->exec("ROLLBACK TO SAVEPOINT {$savepoint}");
            } catch (\Throwable) {
                // The savepoint is gone, which means the engine has already undone the work it marked.
            }

            throw $e;
        } finally {
            $this->transactionDepth--;
        }
    }
}
