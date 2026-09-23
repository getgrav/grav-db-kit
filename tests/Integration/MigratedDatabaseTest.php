<?php

declare(strict_types=1);

namespace TrilbyMedia\GravDbKit\Tests\Integration;

use PHPUnit\Framework\TestCase;
use TrilbyMedia\GravDbKit\Database\KitOptions;
use TrilbyMedia\GravDbKit\Database\KitTables;
use TrilbyMedia\GravDbKit\Testing\EngineProvider;
use TrilbyMedia\GravDbKit\Testing\MigratedDatabase;
use TrilbyMedia\GravDbKit\Tests\Support\TestEngine;

final class MigratedDatabaseTest extends TestCase
{
    public function testTheSnapshotReplaysTheSchemaAndTheSeedRows(): void
    {
        $factory = new MigratedDatabase(TestEngine::migrationsPath());

        $first = $factory->sqlite();
        $second = $factory->sqlite();

        foreach ([$first, $second] as $db) {
            self::assertSame('Seeded widget', $db->fetchValue("SELECT name FROM kit_t_widgets WHERE sku = 'seed'"));
            self::assertSame(5, (int)$db->fetchValue('SELECT COUNT(*) FROM kit_migrations'));
            self::assertTrue($factory->migrator($db)->isUpToDate(), 'a replayed database needs no migrating');
        }

        // Unique constraints and indexes come across too.
        $second->insert('kit_t_widgets', ['sku' => 'two', 'name' => 'Two']);
        try {
            $second->insert('kit_t_widgets', ['sku' => 'two', 'name' => 'Again']);
            self::fail('the unique constraint should have been replayed');
        } catch (\PDOException $e) {
            self::assertTrue($second->isUniqueViolation($e));
        }

        self::assertSame(1, (int)$first->fetchValue('SELECT COUNT(*) FROM kit_t_widgets'), 'each database is its own');
    }

    public function testMigrateEachSkipsTheSnapshot(): void
    {
        putenv('GRAVDBKIT_TEST_MIGRATE_EACH=1');
        try {
            $db = (new MigratedDatabase(TestEngine::migrationsPath()))->sqlite();
            self::assertSame(5, (int)$db->fetchValue('SELECT COUNT(*) FROM kit_migrations'));
        } finally {
            putenv('GRAVDBKIT_TEST_MIGRATE_EACH');
        }
    }

    public function testTheConnectionFollowsTheSelectedEngine(): void
    {
        $db = (new MigratedDatabase(TestEngine::migrationsPath(), engines: TestEngine::provider()))->connection();

        self::assertSame(TestEngine::name(), $db->dialect()->name());
        self::assertSame(1, (int)$db->fetchValue('SELECT COUNT(*) FROM kit_t_widgets'));
    }

    public function testOptionsReachTheConnection(): void
    {
        $db = (new MigratedDatabase(TestEngine::migrationsPath(), options: new KitOptions(savepointPrefix: 'hd_sp_')))->sqlite();

        self::assertSame('hd_sp_', $db->options()->savepointPrefix);
    }

    /** Without a provider, a server run drops the tables under the kit tables' shared prefix, never everything. */
    public function testTheDefaultProviderDropsOnlyThePluginsTables(): void
    {
        $provider = (new MigratedDatabase(TestEngine::migrationsPath(), KitTables::withPrefix('helpdesk')))->engines();
        $db = TestEngine::fresh();
        $d = $db->dialect();
        $db->run("CREATE TABLE helpdesk_tickets (id {$d->primaryKey()}) {$d->tableOptions()}");
        $db->run("CREATE TABLE kit_t_bystander (id {$d->primaryKey()}) {$d->tableOptions()}");

        $provider->dropTables($db);

        self::assertFalse($d->tableExists($db->pdo(), 'helpdesk_tickets'));
        self::assertTrue($d->tableExists($db->pdo(), 'kit_t_bystander'));
        $db->run('DROP TABLE kit_t_bystander');
    }

    public function testAServerEngineWithoutADsnIsAnErrorNotASkip(): void
    {
        $provider = new EngineProvider('GRAVDBKIT_NOWHERE');

        self::assertFalse($provider->available('mysql'));
        self::assertTrue($provider->available('sqlite'));

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('GRAVDBKIT_NOWHERE_PGSQL_DSN is not set');
        $provider->freshOn('pgsql');
    }
}
