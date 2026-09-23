<?php

declare(strict_types=1);

namespace TrilbyMedia\GravDbKit\Database;

use TrilbyMedia\GravDbKit\Database\Dialect\Dialect;

/**
 * A migration file (one per `NNNN_name.php` in a migrations directory) returns an instance of this interface.
 *
 * Steps are applied in array order and recorded individually — MySQL DDL
 * auto-commits, so a crash can land between steps. Every step MUST therefore
 * be idempotent (guard CREATEs with tableExists()/createIndexIfMissing(),
 * seeds with an existence check).
 */
interface Migration
{
    /** Must match the filename without extension, e.g. '0001_infra'. */
    public function name(): string;

    /**
     * @return array<string, callable(Connection): void> ordered, idempotent steps
     */
    public function steps(Dialect $dialect): array;
}
