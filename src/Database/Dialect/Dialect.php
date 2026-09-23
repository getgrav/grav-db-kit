<?php

declare(strict_types=1);

namespace TrilbyMedia\GravDbKit\Database\Dialect;

/**
 * Per-engine SQL portability layer.
 *
 * Everything outside this interface (and the migrations' DDL fragments built
 * from it) must stick to the portable SQL subset documented on Connection.
 */
interface Dialect
{
    /** Engine name: 'sqlite' | 'mysql' | 'pgsql'. */
    public function name(): string;

    /**
     * DDL fragment for the surrogate autoincrement primary key column.
     * SQLite requires INTEGER (rowid alias); MySQL/Postgres use BIGINT.
     */
    public function primaryKey(): string;

    /** Engine type for unbounded long text (long bodies, JSON payloads). */
    public function textLong(): string;

    /** Trailing CREATE TABLE options (engine/charset on MySQL, empty elsewhere). */
    public function tableOptions(): string;

    /** Quote an identifier after validating it against [A-Za-z_][A-Za-z0-9_]*. */
    public function quoteIdentifier(string $identifier): string;

    /**
     * Insert a row and return the generated id. Postgres uses RETURNING id
     * (lastInsertId() needs a sequence name there); the others use lastInsertId().
     *
     * @param array<string, mixed> $row column => value
     */
    public function insertReturningId(\PDO $pdo, string $table, array $row): int;

    /**
     * Insert, or update $updateColumns when the $conflictColumns unique key
     * already exists. With empty $updateColumns behaves as insert-ignore.
     *
     * @param array<string, mixed> $row
     * @param string[] $conflictColumns
     * @param string[] $updateColumns
     */
    public function upsert(\PDO $pdo, string $table, array $row, array $conflictColumns, array $updateColumns): void;

    /**
     * Insert many rows in as few statements as the engine's bind-parameter
     * limit allows, with the primary key supplied rather than generated.
     *
     * Bulk loading is the one place where the id has to be known before the
     * row is written: in KahunaCart's benchmark seeder a quarter of a million
     * orders each own items, adjustments and history rows, and reading every
     * generated id back would cost more round trips than the insert itself.
     * Supplying it is the difference between a seeded store in minutes and
     * one overnight.
     *
     * The three engines differ only in what they do about the identity column.
     * SQLite's AUTOINCREMENT and MySQL's AUTO_INCREMENT both accept an explicit
     * value and carry their counter past it; Postgres declares its keys
     * GENERATED ALWAYS, which refuses one without OVERRIDING SYSTEM VALUE and
     * then leaves the sequence behind — so PgsqlDialect writes the clause and
     * syncIdentitySequence() puts the sequence back where it belongs.
     *
     * @param string[] $columns
     * @param list<list<mixed>> $rows one value per column, in $columns order
     */
    public function insertMany(\PDO $pdo, string $table, array $columns, array $rows): void;

    /**
     * Put a table's identity sequence past the largest id in it.
     *
     * A no-op except on Postgres, whose identity sequence does not advance for
     * a value that overrode it. Without this the first ordinary insert after a
     * bulk load collides with row 1.
     */
    public function syncIdentitySequence(\PDO $pdo, string $table, string $column = 'id'): void;

    /**
     * How many bound parameters one statement may carry.
     *
     * The cap on how many rows insertMany() can put in a statement, since
     * every value is bound rather than inlined.
     */
    public function maxBindParameters(\PDO $pdo): int;

    public function tableExists(\PDO $pdo, string $table): bool;

    public function columnExists(\PDO $pdo, string $table, string $column): bool;

    /**
     * Rename a table, but only when the old name is still there and the new
     * one is not: a migration step that has already run has to be a no-op
     * rather than an error, like every other step in this schema.
     *
     * The three engines do not spell this alike. SQLite and Postgres both take
     * ALTER TABLE ... RENAME TO; MySQL prefers RENAME TABLE, which is also the
     * only form MariaDB has always had. All three carry the table's data,
     * primary key sequence and indexes across with it, so nothing is rebuilt
     * and no id changes.
     *
     * Returns true when it actually renamed something.
     */
    public function renameTableIfExists(\PDO $pdo, string $from, string $to): bool;

    /**
     * Drop an index by name when it exists. Used after a rename so an index
     * carried across under the old table's name can be replaced by one named
     * after the new table. SQLite and Postgres have DROP INDEX IF EXISTS;
     * MySQL drops through ALTER TABLE and needs the information_schema probe
     * createIndexIfMissing() already uses.
     */
    public function dropIndexIfExists(\PDO $pdo, string $table, string $indexName): void;

    /**
     * CREATE INDEX only when missing. SQLite/Postgres support IF NOT EXISTS;
     * MySQL needs an information_schema probe.
     *
     * @param string[] $columns
     */
    public function createIndexIfMissing(\PDO $pdo, string $table, string $indexName, array $columns, bool $unique = false): void;

    /** Whether a PDOException represents a transient conflict worth retrying (deadlock, busy). */
    public function isRetryableError(\PDOException $e): bool;

    /**
     * Whether a PDOException is the engine saying the row is already there.
     *
     * Each engine says it differently. PostgreSQL keeps a state of its own for a duplicate key. MySQL and MariaDB file every integrity failure under 23000 and only the driver number tells them apart. SQLite answers SQLITE_CONSTRAINT (19) for all of them, so a NOT NULL failure and a trigger's own abort arrive looking exactly like a duplicate and only the message separates them. Everything else is a real error and says false, which is what keeps a deadlock out of a duplicate-key recovery path.
     */
    public function isUniqueViolation(\PDOException $e): bool;
}
