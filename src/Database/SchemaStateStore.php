<?php

declare(strict_types=1);

namespace TrilbyMedia\GravDbKit\Database;

/**
 * Where SchemaGuard remembers "this exact set of migration files was found up
 * to date", so the next request can skip the whole check with one read.
 *
 * Two implementations: KvSchemaState keeps the fingerprint in the plugin's own
 * KV table (Forum Pro's `migrations.complete`), CallbackSchemaState hands it
 * to closures (KahunaCart keeps it in Grav's cache, which `bin/grav clear`
 * empties).
 *
 * A store must never throw from remembered(): on a fresh database the table it
 * reads may not exist yet, and "I don't know" (null) is the right answer then.
 */
interface SchemaStateStore
{
    /** The fingerprint last recorded as up to date, or null when there is none. */
    public function remembered(): ?string;

    public function remember(string $fingerprint): void;
}
