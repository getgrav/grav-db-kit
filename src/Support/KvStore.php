<?php

declare(strict_types=1);

namespace TrilbyMedia\GravDbKit\Support;

use TrilbyMedia\GravDbKit\Database\Connection;
use TrilbyMedia\GravDbKit\Database\KitTables;

/**
 * Tiny installation-scoped key-value store (KitTables::$kv). Holds values that
 * belong to the installation rather than any user: the unsubscribe HMAC
 * secret, the site base URL captured for CLI email building, the schema
 * fingerprint KvSchemaState remembers, a worker's last run.
 *
 * The table is created by the plugin's own migration through
 * Schema\InfraTables::kv(); keys are at most 64 characters, the column's
 * width, and a longer one is refused rather than cut, because two long keys
 * that shared a prefix would silently become one.
 */
final class KvStore
{
    public const MAX_KEY_LENGTH = 64;

    private readonly KitTables $tables;
    private readonly Clock $clock;

    public function __construct(
        private readonly Connection $db,
        ?KitTables $tables = null,
        ?Clock $clock = null,
    ) {
        $this->tables = $tables ?? new KitTables();
        $this->clock = $clock ?? new SystemClock();
    }

    public function get(string $key): ?string
    {
        $value = $this->db->fetchValue(
            "SELECT kv_value FROM {$this->tables->kv} WHERE kv_key = ?",
            [self::key($key)]
        );

        return $value !== null ? (string)$value : null;
    }

    public function set(string $key, ?string $value): void
    {
        // upsert, not insert(): the table has no id column, and Postgres
        // inserts append RETURNING id.
        $this->db->upsert($this->tables->kv, [
            'kv_key' => self::key($key),
            'kv_value' => $value,
            'updated_at' => $this->clock->now(),
        ], ['kv_key'], ['kv_value', 'updated_at']);
    }

    public function delete(string $key): void
    {
        $this->db->delete($this->tables->kv, 'kv_key = ?', [self::key($key)]);
    }

    /**
     * Get-or-create for values that must exist exactly once.
     *
     * The created value is written insert-if-absent and then read back, so two
     * requests that both find nothing agree on whichever value landed first. A
     * plain set() here would let the second overwrite the first, and a secret
     * that changes under a link already sent breaks that link.
     *
     * @param callable(): (string|\Stringable) $create
     */
    public function remember(string $key, callable $create): string
    {
        $value = $this->get($key);
        if ($value !== null) {
            return $value;
        }

        $created = (string)$create();
        $this->db->upsert($this->tables->kv, [
            'kv_key' => self::key($key),
            'kv_value' => $created,
            'updated_at' => $this->clock->now(),
        ], ['kv_key'], []);

        $stored = $this->get($key);
        if ($stored === null) {
            // The row was there all along holding NULL, which the insert left alone.
            $this->set($key, $created);

            return $created;
        }

        return $stored;
    }

    private static function key(string $key): string
    {
        if ($key === '' || \strlen($key) > self::MAX_KEY_LENGTH) {
            throw new \InvalidArgumentException('KV keys are 1 to ' . self::MAX_KEY_LENGTH . " bytes: '{$key}'");
        }

        return $key;
    }
}
