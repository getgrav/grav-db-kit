<?php

declare(strict_types=1);

namespace TrilbyMedia\GravDbKit\Database\Dialect;

final class PgsqlDialect extends AbstractDialect
{
    public function name(): string
    {
        return 'pgsql';
    }

    public function primaryKey(): string
    {
        return 'BIGINT GENERATED ALWAYS AS IDENTITY PRIMARY KEY';
    }

    public function textLong(): string
    {
        return 'TEXT';
    }

    public function tableOptions(): string
    {
        return '';
    }

    public function insertReturningId(\PDO $pdo, string $table, array $row): int
    {
        // RETURNING avoids lastInsertId()'s sequence-name requirement on Postgres.
        $sql = $this->buildInsertSql($table, $row) . ' RETURNING id';
        $stmt = $pdo->prepare($sql);
        $stmt->execute(array_values($row));

        return (int)$stmt->fetchColumn();
    }

    public function columnExists(\PDO $pdo, string $table, string $column): bool
    {
        $stmt = $pdo->prepare(
            'SELECT 1 FROM information_schema.columns
             WHERE table_schema = current_schema() AND table_name = ? AND column_name = ?'
        );
        $stmt->execute([$table, $column]);

        return $stmt->fetchColumn() !== false;
    }

    public function upsert(\PDO $pdo, string $table, array $row, array $conflictColumns, array $updateColumns): void
    {
        $sql = $this->buildInsertSql($table, $row);
        $conflict = implode(', ', array_map($this->quoteIdentifier(...), $conflictColumns));

        if ($updateColumns === []) {
            $sql .= " ON CONFLICT ({$conflict}) DO NOTHING";
        } else {
            $sets = array_map(
                fn (string $col): string => $this->quoteIdentifier($col) . ' = EXCLUDED.' . $this->quoteIdentifier($col),
                $updateColumns
            );
            $sql .= " ON CONFLICT ({$conflict}) DO UPDATE SET " . implode(', ', $sets);
        }

        $stmt = $pdo->prepare($sql);
        $stmt->execute(array_values($row));
    }

    public function tableExists(\PDO $pdo, string $table): bool
    {
        $stmt = $pdo->prepare('SELECT to_regclass(?)');
        $stmt->execute([$table]);

        return $stmt->fetchColumn() !== null;
    }

    public function syncIdentitySequence(\PDO $pdo, string $table, string $column = 'id'): void
    {
        // A value written past GENERATED ALWAYS does not move the sequence, so
        // the next ordinary insert would collide with the first bulk-loaded
        // row. setval() with `false` means "the next value is this one".
        $sequence = $pdo->prepare('SELECT pg_get_serial_sequence(?, ?)');
        $sequence->execute([$table, $column]);
        $name = $sequence->fetchColumn();

        if (!\is_string($name) || $name === '') {
            return;
        }

        $max = $pdo->query(sprintf(
            'SELECT COALESCE(MAX(%s), 0) FROM %s',
            $this->quoteIdentifier($column),
            $this->quoteIdentifier($table)
        ));

        $stmt = $pdo->prepare('SELECT setval(?, ?, false)');
        $stmt->execute([$name, ((int)($max === false ? 0 : $max->fetchColumn())) + 1]);
    }

    protected function insertManyOverride(array $columns): string
    {
        return \in_array('id', $columns, true) ? ' OVERRIDING SYSTEM VALUE' : '';
    }

    public function isRetryableError(\PDOException $e): bool
    {
        // 40001 serialization failure, 40P01 deadlock
        $state = $e->errorInfo[0] ?? (string)$e->getCode();

        return $state === '40001' || $state === '40P01';
    }

    public function isUniqueViolation(\PDOException $e): bool
    {
        // 23505 unique_violation, which this engine keeps apart from every other integrity failure.
        return (string)($e->errorInfo[0] ?? $e->getCode()) === '23505';
    }

    protected function quoteChar(): string
    {
        return '"';
    }
}
