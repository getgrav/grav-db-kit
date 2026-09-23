<?php

declare(strict_types=1);

namespace TrilbyMedia\GravDbKit\Database\Dialect;

abstract class AbstractDialect implements Dialect
{
    public function quoteIdentifier(string $identifier): string
    {
        if (!preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $identifier)) {
            throw new \InvalidArgumentException("Invalid SQL identifier: {$identifier}");
        }

        return $this->quoteChar() . $identifier . $this->quoteChar();
    }

    public function insertReturningId(\PDO $pdo, string $table, array $row): int
    {
        $stmt = $pdo->prepare($this->buildInsertSql($table, $row));
        $stmt->execute(array_values($row));

        return (int)$pdo->lastInsertId();
    }

    public function insertMany(\PDO $pdo, string $table, array $columns, array $rows): void
    {
        if ($rows === [] || $columns === []) {
            return;
        }

        $perStatement = max(1, intdiv($this->maxBindParameters($pdo), \count($columns)));

        foreach (array_chunk($rows, $perStatement) as $chunk) {
            // One prepared statement per chunk size, and every chunk but the
            // last is the same size — so the driver's statement cache sees the
            // same SQL over and over rather than a new string per batch.
            $stmt = $pdo->prepare($this->buildInsertManySql($table, $columns, \count($chunk)));
            $stmt->execute(array_merge(...array_map(array_values(...), $chunk)));
        }
    }

    public function syncIdentitySequence(\PDO $pdo, string $table, string $column = 'id'): void
    {
        // SQLite and MySQL both advance their counter past an explicit key.
    }

    public function maxBindParameters(\PDO $pdo): int
    {
        // MySQL and Postgres both stop at 65,535 placeholders in one statement.
        return 60_000;
    }

    public function columnExists(\PDO $pdo, string $table, string $column): bool
    {
        $stmt = $pdo->prepare(
            'SELECT 1 FROM information_schema.columns WHERE table_name = ? AND column_name = ?'
        );
        $stmt->execute([$table, $column]);

        return $stmt->fetchColumn() !== false;
    }

    public function createIndexIfMissing(\PDO $pdo, string $table, string $indexName, array $columns, bool $unique = false): void
    {
        $cols = implode(', ', array_map($this->quoteIdentifier(...), $columns));
        $uniqueSql = $unique ? 'UNIQUE ' : '';
        $pdo->exec(sprintf(
            'CREATE %sINDEX IF NOT EXISTS %s ON %s (%s)',
            $uniqueSql,
            $this->quoteIdentifier($indexName),
            $this->quoteIdentifier($table),
            $cols
        ));
    }

    public function renameTableIfExists(\PDO $pdo, string $from, string $to): bool
    {
        if (!$this->tableExists($pdo, $from) || $this->tableExists($pdo, $to)) {
            return false;
        }

        $pdo->exec(sprintf(
            'ALTER TABLE %s RENAME TO %s',
            $this->quoteIdentifier($from),
            $this->quoteIdentifier($to)
        ));

        return true;
    }

    public function dropIndexIfExists(\PDO $pdo, string $table, string $indexName): void
    {
        $pdo->exec('DROP INDEX IF EXISTS ' . $this->quoteIdentifier($indexName));
    }

    /**
     * @param array<string, mixed> $row
     */
    protected function buildInsertSql(string $table, array $row): string
    {
        $columns = array_map($this->quoteIdentifier(...), array_keys($row));
        $placeholders = implode(', ', array_fill(0, count($row), '?'));

        return sprintf(
            'INSERT INTO %s (%s) VALUES (%s)',
            $this->quoteIdentifier($table),
            implode(', ', $columns),
            $placeholders
        );
    }

    /**
     * `INSERT INTO t (a, b) VALUES (?, ?), (?, ?), …` — the multi-row VALUES
     * list, which all three engines have had for as long as any of them has
     * been supported.
     *
     * @param string[] $columns
     */
    protected function buildInsertManySql(string $table, array $columns, int $rowCount): string
    {
        $tuple = '(' . implode(', ', array_fill(0, \count($columns), '?')) . ')';

        return sprintf(
            'INSERT INTO %s (%s)%s VALUES %s',
            $this->quoteIdentifier($table),
            implode(', ', array_map($this->quoteIdentifier(...), $columns)),
            $this->insertManyOverride($columns),
            implode(', ', array_fill(0, $rowCount, $tuple))
        );
    }

    /**
     * Clause between the column list and VALUES; only Postgres needs one.
     *
     * @param string[] $columns
     */
    protected function insertManyOverride(array $columns): string
    {
        return '';
    }

    abstract protected function quoteChar(): string;
}
