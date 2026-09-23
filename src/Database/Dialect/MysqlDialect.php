<?php

declare(strict_types=1);

namespace TrilbyMedia\GravDbKit\Database\Dialect;

final class MysqlDialect extends AbstractDialect
{
    public function name(): string
    {
        return 'mysql';
    }

    public function primaryKey(): string
    {
        return 'BIGINT NOT NULL AUTO_INCREMENT PRIMARY KEY';
    }

    public function textLong(): string
    {
        // TEXT caps at 64KB which a long description or JSON payload can exceed.
        return 'MEDIUMTEXT';
    }

    public function tableOptions(): string
    {
        // utf8mb4_unicode_ci rather than utf8mb4_0900_ai_ci so MariaDB works too.
        return 'ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci';
    }

    public function columnExists(\PDO $pdo, string $table, string $column): bool
    {
        $stmt = $pdo->prepare(
            'SELECT 1 FROM information_schema.columns
             WHERE table_schema = DATABASE() AND table_name = ? AND column_name = ?'
        );
        $stmt->execute([$table, $column]);

        return $stmt->fetchColumn() !== false;
    }

    public function upsert(\PDO $pdo, string $table, array $row, array $conflictColumns, array $updateColumns): void
    {
        // MySQL keys off any unique index, not the listed columns; $conflictColumns
        // is informational here but the unique index must exist.
        $sql = $this->buildInsertSql($table, $row);

        if ($updateColumns === []) {
            // No-op update keeps INSERT IGNORE semantics without swallowing
            // unrelated errors the way INSERT IGNORE does.
            $first = $this->quoteIdentifier((string)array_key_first($row));
            $sql .= " ON DUPLICATE KEY UPDATE {$first} = {$first}";
        } else {
            $sets = array_map(
                fn (string $col): string => $this->quoteIdentifier($col) . ' = VALUES(' . $this->quoteIdentifier($col) . ')',
                $updateColumns
            );
            $sql .= ' ON DUPLICATE KEY UPDATE ' . implode(', ', $sets);
        }

        $stmt = $pdo->prepare($sql);
        $stmt->execute(array_values($row));
    }

    public function tableExists(\PDO $pdo, string $table): bool
    {
        $stmt = $pdo->prepare(
            'SELECT 1 FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = ?'
        );
        $stmt->execute([$table]);

        return $stmt->fetchColumn() !== false;
    }

    public function createIndexIfMissing(\PDO $pdo, string $table, string $indexName, array $columns, bool $unique = false): void
    {
        // MySQL has no CREATE INDEX IF NOT EXISTS (MariaDB does; use the common subset).
        $stmt = $pdo->prepare(
            'SELECT 1 FROM information_schema.statistics
             WHERE table_schema = DATABASE() AND table_name = ? AND index_name = ? LIMIT 1'
        );
        $stmt->execute([$table, $indexName]);
        if ($stmt->fetchColumn() !== false) {
            return;
        }

        $cols = implode(', ', array_map($this->quoteIdentifier(...), $columns));
        $uniqueSql = $unique ? 'UNIQUE ' : '';
        $pdo->exec(sprintf(
            'CREATE %sINDEX %s ON %s (%s)',
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

        // RENAME TABLE rather than ALTER TABLE ... RENAME TO: it is the form
        // both MySQL and every MariaDB version accept, and it is atomic.
        $pdo->exec(sprintf(
            'RENAME TABLE %s TO %s',
            $this->quoteIdentifier($from),
            $this->quoteIdentifier($to)
        ));

        return true;
    }

    public function dropIndexIfExists(\PDO $pdo, string $table, string $indexName): void
    {
        // MySQL has no DROP INDEX IF EXISTS; probe first, exactly as
        // createIndexIfMissing() does in the other direction.
        $stmt = $pdo->prepare(
            'SELECT 1 FROM information_schema.statistics
             WHERE table_schema = DATABASE() AND table_name = ? AND index_name = ? LIMIT 1'
        );
        $stmt->execute([$table, $indexName]);
        if ($stmt->fetchColumn() === false) {
            return;
        }

        $pdo->exec(sprintf(
            'ALTER TABLE %s DROP INDEX %s',
            $this->quoteIdentifier($table),
            $this->quoteIdentifier($indexName)
        ));
    }

    public function isRetryableError(\PDOException $e): bool
    {
        // 1213 deadlock, 1205 lock wait timeout
        $code = $e->errorInfo[1] ?? null;

        return $code === 1213 || $code === 1205;
    }

    public function isUniqueViolation(\PDOException $e): bool
    {
        // 23000 is every integrity failure here, so the driver number decides: 1062 for a duplicate key and 1586 for the same thing reported with the key's name, which is what MariaDB answers.
        $code = $e->errorInfo[1] ?? null;

        return (string)($e->errorInfo[0] ?? $e->getCode()) === '23000'
            && ($code === 1062 || $code === 1586);
    }

    protected function quoteChar(): string
    {
        return '`';
    }
}
