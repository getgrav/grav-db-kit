<?php

declare(strict_types=1);

namespace TrilbyMedia\GravDbKit\Database;

/**
 * The schema fingerprint kept wherever the plugin likes, through two closures.
 *
 * KahunaCart's arrangement: the answer lives in Grav's cache, so a web request
 * on a server database does not even open a connection to learn nothing has
 * changed, and `bin/grav clear` forces the full check. Because the cache sits
 * outside the database, the closures should fold the database target into
 * their cache key (KahunaCart hashes the engine and SQLite path), or a site
 * pointed at a fresh database would trust an answer about the old one.
 *
 * Whatever the getter throws is treated as "not recorded".
 */
final class CallbackSchemaState implements SchemaStateStore
{
    /** @var \Closure(): (string|null) */
    private readonly \Closure $get;

    /** @var \Closure(string): void */
    private readonly \Closure $set;

    /**
     * @param callable(): (string|null|false) $get returns the recorded fingerprint, or null/false for none
     * @param callable(string): void $set records a fingerprint
     */
    public function __construct(callable $get, callable $set)
    {
        $this->get = $get(...);
        $this->set = $set(...);
    }

    public function remembered(): ?string
    {
        try {
            $value = ($this->get)();
        } catch (\Throwable) {
            return null;
        }

        return \is_string($value) && $value !== '' ? $value : null;
    }

    public function remember(string $fingerprint): void
    {
        ($this->set)($fingerprint);
    }
}
