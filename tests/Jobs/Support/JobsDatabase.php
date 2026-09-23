<?php

declare(strict_types=1);

namespace TrilbyMedia\GravDbKit\Tests\Jobs\Support;

use TrilbyMedia\GravDbKit\Database\Connection;
use TrilbyMedia\GravDbKit\Database\KitTables;
use TrilbyMedia\GravDbKit\Schema\InfraTables;
use TrilbyMedia\GravDbKit\Testing\EngineProvider;

/**
 * A fresh jobs table on whichever engine GRAVDBKIT_TEST_ENGINE names.
 *
 * Every table the job tests make is under `kitjobs_`, not the `kit_` the rest
 * of the suite uses, so a jobs run and a database-core run can share one MySQL
 * or PostgreSQL test database without dropping each other's tables.
 */
final class JobsDatabase
{
    public const PREFIX = 'kitjobs';

    private static ?EngineProvider $provider = null;

    public static function provider(): EngineProvider
    {
        return self::$provider ??= new EngineProvider('GRAVDBKIT_TEST', [self::PREFIX . '_']);
    }

    public static function tables(): KitTables
    {
        return KitTables::withPrefix(self::PREFIX);
    }

    /** The jobs table's name, for tests that read or poke rows directly. */
    public static function table(): string
    {
        return self::tables()->jobs;
    }

    public static function engine(): string
    {
        return self::provider()->engine();
    }

    /** An empty database holding nothing but a freshly made jobs table. */
    public static function fresh(): Connection
    {
        $db = self::provider()->fresh();
        InfraTables::jobs($db->dialect(), self::tables())($db);

        return $db;
    }

    /** A second connection to the database fresh() last made. */
    public static function sibling(): Connection
    {
        return self::provider()->sibling();
    }
}
