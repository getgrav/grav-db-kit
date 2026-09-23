<?php

declare(strict_types=1);

namespace TrilbyMedia\GravDbKit\Database\Dialect;

final class SqliteDialect extends AbstractDialect
{
    public function name(): string
    {
        return 'sqlite';
    }

    public function primaryKey(): string
    {
        // Must be INTEGER (not BIGINT) so the column aliases SQLite's rowid.
        return 'INTEGER PRIMARY KEY AUTOINCREMENT';
    }

    public function textLong(): string
    {
        return 'TEXT';
    }

    public function tableOptions(): string
    {
        return '';
    }

    public function columnExists(\PDO $pdo, string $table, string $column): bool
    {
        $stmt = $pdo->query('PRAGMA table_info(' . $this->quoteIdentifier($table) . ')');
        foreach ($stmt->fetchAll(\PDO::FETCH_ASSOC) as $row) {
            if (strcasecmp((string)$row['name'], $column) === 0) {
                return true;
            }
        }

        return false;
    }

    public function upsert(\PDO $pdo, string $table, array $row, array $conflictColumns, array $updateColumns): void
    {
        $sql = $this->buildInsertSql($table, $row);
        $conflict = implode(', ', array_map($this->quoteIdentifier(...), $conflictColumns));

        if ($updateColumns === []) {
            $sql .= " ON CONFLICT ({$conflict}) DO NOTHING";
        } else {
            $sets = array_map(
                fn (string $col): string => $this->quoteIdentifier($col) . ' = excluded.' . $this->quoteIdentifier($col),
                $updateColumns
            );
            $sql .= " ON CONFLICT ({$conflict}) DO UPDATE SET " . implode(', ', $sets);
        }

        $stmt = $pdo->prepare($sql);
        $stmt->execute(array_values($row));
    }

    public function tableExists(\PDO $pdo, string $table): bool
    {
        $stmt = $pdo->prepare("SELECT 1 FROM sqlite_master WHERE type = 'table' AND name = ?");
        $stmt->execute([$table]);

        return $stmt->fetchColumn() !== false;
    }

    public function maxBindParameters(\PDO $pdo): int
    {
        // SQLITE_MAX_VARIABLE_NUMBER was raised from 999 to 32,766 in 3.32.0
        // and there is no way to read the compiled limit through PDO, so the
        // version is the only thing to go on. The old figure is the safe
        // answer for anything older, and costs only more statements.
        $version = (string)$pdo->getAttribute(\PDO::ATTR_SERVER_VERSION);

        return version_compare($version, '3.32.0', '>=') ? 32_000 : 900;
    }

    public function isRetryableError(\PDOException $e): bool
    {
        // SQLITE_BUSY (5) / SQLITE_LOCKED (6)
        $code = $e->errorInfo[1] ?? null;

        return $code === 5 || $code === 6;
    }

    public function isUniqueViolation(\PDOException $e): bool
    {
        // SQLITE_CONSTRAINT (19) covers every constraint on this engine — NOT NULL, CHECK, foreign key and a trigger's own RAISE(ABORT) all arrive under it — so the message is the only thing that separates a duplicate from the rest. Most builds file it under SQLSTATE 23000 and some under HY000, so both are accepted. Current SQLite words a duplicate primary key as a UNIQUE failure; the other two wordings are what older libraries answer.
        $state = (string)($e->errorInfo[0] ?? $e->getCode());
        if (($state !== '23000' && $state !== 'HY000') || ($e->errorInfo[1] ?? null) !== 19) {
            return false;
        }

        $message = (string)($e->errorInfo[2] ?? $e->getMessage());

        return stripos($message, 'UNIQUE constraint failed') !== false
            || stripos($message, 'PRIMARY KEY constraint failed') !== false
            || stripos($message, 'PRIMARY KEY must be unique') !== false
            || stripos($message, 'is not unique') !== false;
    }

    protected function quoteChar(): string
    {
        return '"';
    }
}
