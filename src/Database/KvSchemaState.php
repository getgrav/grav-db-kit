<?php

declare(strict_types=1);

namespace TrilbyMedia\GravDbKit\Database;

use TrilbyMedia\GravDbKit\Support\KvStore;

/**
 * The schema fingerprint as one KV row, `migrations.complete` by default:
 * Forum Pro's arrangement, where the answer lives in the database it
 * describes, so pointing a site at a different database can never reuse an
 * answer about the old one.
 *
 * The KV table is created by the plugin's own migrations
 * (Schema\InfraTables::kv()), so it is missing on a brand-new database. The
 * read treats any failure as "not recorded"; call SchemaGuard outside an open
 * transaction, because on PostgreSQL a failed statement inside one poisons
 * the rest of it.
 */
final class KvSchemaState implements SchemaStateStore
{
    public const DEFAULT_KEY = 'migrations.complete';

    public function __construct(
        private readonly KvStore $kv,
        private readonly string $key = self::DEFAULT_KEY,
    ) {
    }

    public function remembered(): ?string
    {
        try {
            return $this->kv->get($this->key);
        } catch (\Throwable) {
            return null; // fresh database: the KV table does not exist yet
        }
    }

    public function remember(string $fingerprint): void
    {
        $this->kv->set($this->key, $fingerprint);
    }
}
