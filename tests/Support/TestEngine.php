<?php

declare(strict_types=1);

namespace TrilbyMedia\GravDbKit\Tests\Support;

use TrilbyMedia\GravDbKit\Database\Connection;
use TrilbyMedia\GravDbKit\Testing\EngineProvider;
use TrilbyMedia\GravDbKit\Testing\MigratedDatabase;

/**
 * The kit's own suite runs against whichever engine GRAVDBKIT_TEST_ENGINE
 * names. Every table a test makes is under `kit_`, so fresh() can clear a
 * shared server database between tests.
 */
final class TestEngine
{
    private static ?EngineProvider $provider = null;

    public static function provider(): EngineProvider
    {
        return self::$provider ??= new EngineProvider('GRAVDBKIT_TEST', ['kit_']);
    }

    public static function name(): string
    {
        return self::provider()->engine();
    }

    public static function fresh(): Connection
    {
        return self::provider()->fresh();
    }

    public static function sibling(): Connection
    {
        return self::provider()->sibling();
    }

    public static function migrationsPath(): string
    {
        return \dirname(__DIR__) . '/fixtures/migrations';
    }

    /** The fixture migrations applied on the selected engine. */
    public static function migrated(): Connection
    {
        return (new MigratedDatabase(self::migrationsPath(), engines: self::provider()))->connection();
    }
}
