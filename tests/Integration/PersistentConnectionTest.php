<?php

declare(strict_types=1);

namespace TrilbyMedia\GravDbKit\Tests\Integration;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use TrilbyMedia\GravDbKit\Database\Connection;
use TrilbyMedia\GravDbKit\Database\ConnectionFactory;
use TrilbyMedia\GravDbKit\Database\KitOptions;
use TrilbyMedia\GravDbKit\Tests\Support\TestEngine;

/**
 * Persistent connections against real MySQL/MariaDB and PostgreSQL servers.
 *
 * PDO keeps a persistent handle for the life of the process, so one PHPUnit process stands in for one PHP-FPM worker. The end of a request is the moment every Connection on the handle has been freed, which is what `unset()` does here, and is also what the factory itself looks for (see ConnectionFactory). Each test is a claim the ConnectionFactory docblock makes.
 *
 * Unlike the rest of the integration suite these do not follow GRAVDBKIT_TEST_ENGINE: each server engine runs whenever its DSN is set (GRAVDBKIT_TEST_MYSQL_DSN/_USER/_PASS, GRAVDBKIT_TEST_PGSQL_DSN/_USER/_PASS) and is skipped otherwise. The DSN is taken apart into the config array ConnectionFactory::mysql() and ::pgsql() read.
 */
final class PersistentConnectionTest extends TestCase
{
    private const PROBE_TABLE = 'kit_persistent_probe';

    private const KEY = 'gravdbkit-test';

    /** @return array<string, array{string}> */
    public static function engines(): array
    {
        return ['mysql' => ['mysql'], 'pgsql' => ['pgsql']];
    }

    #[DataProvider('engines')]
    public function testTheNextRequestGetsTheSameServerConnection(string $type): void
    {
        $first = $this->open($type);
        self::assertTrue((bool)$first->pdo()->getAttribute(\PDO::ATTR_PERSISTENT));
        $id = $this->backendId($first);
        unset($first);

        $second = $this->open($type);

        self::assertSame($id, $this->backendId($second));
        self::assertTrue((bool)$second->pdo()->getAttribute(\PDO::ATTR_PERSISTENT));
    }

    #[DataProvider('engines')]
    public function testItIsOffByDefaultAndOffIsAPlainConnection(string $type): void
    {
        $cfg = $this->configFor($type);
        $options = new KitOptions(persistentKey: self::KEY);

        foreach ([[], ['persistent' => false], ['persistent' => '0'], ['persistent' => '']] as $setting) {
            $a = ConnectionFactory::create(['type' => $type, $type => $setting + $cfg], $options);
            $b = ConnectionFactory::create(['type' => $type, $type => $setting + $cfg], $options);

            self::assertFalse((bool)$a->pdo()->getAttribute(\PDO::ATTR_PERSISTENT));
            self::assertNotSame($this->backendId($a), $this->backendId($b), 'two plain connections share nothing');
        }
    }

    /** A top-level `persistent` in a create() config reaches the selected engine; the engine's own block wins. */
    #[DataProvider('engines')]
    public function testCreateReadsATopLevelToggle(string $type): void
    {
        $cfg = $this->configFor($type);
        $options = new KitOptions(persistentKey: self::KEY);

        $on = ConnectionFactory::create(['type' => $type, 'persistent' => true, $type => $cfg], $options);
        self::assertTrue((bool)$on->pdo()->getAttribute(\PDO::ATTR_PERSISTENT));

        $overridden = ConnectionFactory::create(['type' => $type, 'persistent' => true, $type => ['persistent' => false] + $cfg], $options);
        self::assertFalse((bool)$overridden->pdo()->getAttribute(\PDO::ATTR_PERSISTENT));
    }

    /**
     * What a previous request can leave behind. PDO rolls back itself when the PDO object is freed, so the only way to reach a pickup with a transaction still open is a PDO the factory did not hand out, holding the same handle; that stands in for the last request's leftovers.
     */
    #[DataProvider('engines')]
    public function testATransactionLeftOpenIsRolledBackOnPickup(string $type): void
    {
        $setup = $this->open($type);
        $setup->run('DROP TABLE IF EXISTS ' . self::PROBE_TABLE);
        $setup->run('CREATE TABLE ' . self::PROBE_TABLE . ' (id INT)');
        unset($setup);

        $leftover = $this->rawPersistentPdo($type, self::KEY);
        $leftover->beginTransaction();
        $leftover->exec('INSERT INTO ' . self::PROBE_TABLE . ' VALUES (1)');

        $next = $this->open($type);

        self::assertFalse($next->pdo()->inTransaction());
        self::assertFalse($leftover->inTransaction(), 'the leftover shares the handle, so it sees the rollback');
        self::assertFalse($next->inTransaction());
        self::assertSame(0, (int)$next->fetchValue('SELECT COUNT(*) FROM ' . self::PROBE_TABLE));

        // Connection's own bookkeeping agrees with the server: a transaction and a savepoint work straight away.
        $next->transaction(static function (Connection $c): void {
            $c->execute('INSERT INTO ' . self::PROBE_TABLE . ' VALUES (2)');
            $c->transaction(static fn (Connection $c) => $c->execute('INSERT INTO ' . self::PROBE_TABLE . ' VALUES (3)'));
        });
        self::assertSame(2, (int)$next->fetchValue('SELECT COUNT(*) FROM ' . self::PROBE_TABLE));

        $next->run('DROP TABLE ' . self::PROBE_TABLE);
        unset($leftover);
    }

    /**
     * A second pickup while the first Connection is still held must not roll back its work in progress, and must not open a second PDO object either: freeing one rolls back the handle's transaction, so the second caller letting go would undo the first caller's work.
     */
    #[DataProvider('engines')]
    public function testAPickupWhileTheHandleIsInUseSharesItAndLeavesItAlone(string $type): void
    {
        $first = $this->open($type);
        $first->run('DROP TABLE IF EXISTS ' . self::PROBE_TABLE);
        $first->run('CREATE TABLE ' . self::PROBE_TABLE . ' (id INT)');

        $first->transaction(function (Connection $c) use ($type): void {
            $c->execute('INSERT INTO ' . self::PROBE_TABLE . ' VALUES (1)');

            $same = $this->open($type);
            self::assertSame($c, $same, 'equal options get the Connection already open');
            self::assertTrue($same->inTransaction());

            // Nested through the shared Connection, so it is a savepoint of the open transaction.
            $same->transaction(static fn (Connection $n) => $n->execute('INSERT INTO ' . self::PROBE_TABLE . ' VALUES (2)'));

            $other = ConnectionFactory::create(
                ['type' => $type, $type => ['persistent' => true] + $this->configFor($type)],
                new KitOptions(savepointPrefix: 'other_sp_', persistentKey: self::KEY)
            );
            self::assertNotSame($c, $other, 'different options get a Connection of their own');
            self::assertSame($c->pdo(), $other->pdo(), 'over the same PDO object');
            self::assertTrue($other->pdo()->inTransaction());
            self::assertSame($c, $this->open($type), 'and the first Connection is still the one equal options get');

            unset($same, $other);
        });

        self::assertSame(2, (int)$first->fetchValue('SELECT COUNT(*) FROM ' . self::PROBE_TABLE), 'the first transaction committed both rows');
        $first->run('DROP TABLE ' . self::PROBE_TABLE);
    }

    /** A statement keeps its PDO alive, so it counts as the handle being in use. */
    #[DataProvider('engines')]
    public function testAStatementStillHeldKeepsTheHandleInUse(string $type): void
    {
        $first = $this->open($type);
        $first->pdo()->beginTransaction();
        $statement = $first->run('SELECT 1');
        unset($first);

        $second = $this->open($type);

        self::assertTrue($second->pdo()->inTransaction());
        $second->pdo()->rollBack();
        unset($statement);
    }

    public function testPostgresSessionStateIsDiscardedOnPickup(): void
    {
        $first = $this->open('pgsql');
        $default = (string)$first->fetchValue('SHOW TimeZone');
        $first->run("SET TIME ZONE 'Pacific/Chatham'");
        $first->fetchValue('SELECT pg_advisory_lock(424242)');
        $first->run('CREATE TEMPORARY TABLE kit_persistent_temp (id INT)');
        $id = $this->backendId($first);
        unset($first);

        $next = $this->open('pgsql');

        self::assertSame($id, $this->backendId($next), 'the same server session');
        self::assertSame($default, (string)$next->fetchValue('SHOW TimeZone'));
        self::assertSame(0, (int)$next->fetchValue(
            "SELECT COUNT(*) FROM pg_locks WHERE locktype = 'advisory' AND pid = pg_backend_pid()"
        ));
        self::assertFalse($next->dialect()->tableExists($next->pdo(), 'kit_persistent_temp'));
    }

    #[DataProvider('engines')]
    public function testAConnectionTheServerClosedIsReplaced(string $type): void
    {
        $first = $this->open($type);
        $id = $this->backendId($first);
        unset($first);

        // What a server restart or an idle timeout does to a worker's handle.
        $admin = $this->plain($type);
        $admin->run($type === 'mysql' ? "KILL {$id}" : "SELECT pg_terminate_backend({$id})");
        usleep(100_000);

        $next = $this->open($type);

        self::assertNotSame($id, $this->backendId($next));
        self::assertSame(1, (int)$next->fetchValue('SELECT 1'));
        self::assertTrue((bool)$next->pdo()->getAttribute(\PDO::ATTR_PERSISTENT));
    }

    /** `persistent_key` in the config wins over KitOptions, top level or in the engine block. */
    #[DataProvider('engines')]
    public function testAPersistentKeyInTheConfigWinsOverKitOptions(string $type): void
    {
        $cfg = $this->configFor($type);
        $options = new KitOptions(persistentKey: self::KEY);
        $ours = $this->open($type);

        $topLevel = ConnectionFactory::create(['type' => $type, 'persistent' => true, 'persistent_key' => 'from-config', $type => $cfg], $options);
        $inBlock = ConnectionFactory::create(['type' => $type, $type => ['persistent' => true, 'persistent_key' => 'from-config'] + $cfg], $options);

        self::assertNotSame($this->backendId($ours), $this->backendId($topLevel));
        self::assertSame($topLevel, $inBlock, 'the same key and options are the same handle');
    }

    #[DataProvider('engines')]
    public function testAnotherPersistentKeyToTheSameDatabaseIsNotShared(string $type): void
    {
        // Another component's persistent PDO with PDO's default key.
        $theirs = $this->rawPersistentPdo($type, true);
        $theirs->beginTransaction();
        $theirId = (string)$theirs->query($type === 'mysql' ? 'SELECT CONNECTION_ID()' : 'SELECT pg_backend_pid()')->fetchColumn();

        $ours = $this->open($type);
        $otherPlugin = $this->open($type, 'another-plugin');

        self::assertNotSame($theirId, $this->backendId($ours));
        self::assertNotSame($this->backendId($ours), $this->backendId($otherPlugin));
        self::assertTrue($theirs->inTransaction(), 'the pickup reset reached a transaction that was not ours');
        $theirs->rollBack();
    }

    /**
     * Everything the server can tell us about a connection, a plain one and a persistent one side by side: the persistent path must differ in nothing but ATTR_PERSISTENT.
     */
    #[DataProvider('engines')]
    public function testTheServerSeesTheSameSessionAsAPlainConnection(string $type): void
    {
        $plain = $this->plain($type);
        $persistent = $this->open($type);

        self::assertSame($this->describe($plain, $type), $this->describe($persistent, $type));
        self::assertSame($plain->dialect()->name(), $persistent->dialect()->name());
    }

    /** @return array<string, mixed> */
    private function describe(Connection $connection, string $type): array
    {
        $pdo = $connection->pdo();
        $described = [
            'errmode' => $pdo->getAttribute(\PDO::ATTR_ERRMODE),
            'fetch' => $pdo->getAttribute(\PDO::ATTR_DEFAULT_FETCH_MODE),
        ];

        if ($type === 'mysql') {
            $described['emulate'] = $pdo->getAttribute(\PDO::ATTR_EMULATE_PREPARES);

            return $described + (array)$connection->fetchRow(
                'SELECT @@character_set_client AS cs_client, @@character_set_connection AS cs_connection,'
                . ' @@character_set_results AS cs_results, @@collation_connection AS collation,'
                . ' @@session.time_zone AS tz, @@session.sql_mode AS sql_mode, @@session.autocommit AS autocommit,'
                . ' @@session.transaction_isolation AS isolation'
            );
        }

        return $described + [
            'encoding' => $connection->fetchValue('SHOW client_encoding'),
            'tz' => $connection->fetchValue('SHOW TimeZone'),
            'search_path' => $connection->fetchValue('SHOW search_path'),
            'datestyle' => $connection->fetchValue('SHOW DateStyle'),
            'isolation' => $connection->fetchValue('SHOW transaction_isolation'),
            'ssl' => $connection->fetchValue('SELECT ssl FROM pg_stat_ssl WHERE pid = pg_backend_pid()'),
        ];
    }

    private function backendId(Connection $connection): string
    {
        return (string)$connection->fetchValue(
            $connection->dialect()->name() === 'mysql' ? 'SELECT CONNECTION_ID()' : 'SELECT pg_backend_pid()'
        );
    }

    private function open(string $type, string $key = self::KEY): Connection
    {
        return ConnectionFactory::create(
            ['type' => $type, $type => ['persistent' => true] + $this->configFor($type)],
            new KitOptions(persistentKey: $key)
        );
    }

    private function plain(string $type): Connection
    {
        return ConnectionFactory::create(['type' => $type, $type => $this->configFor($type)]);
    }

    /** A persistent PDO opened without the factory, on the DSN the factory builds. */
    private function rawPersistentPdo(string $type, string|bool $key): \PDO
    {
        $cfg = $this->configFor($type);
        $dsn = $type === 'mysql'
            ? (isset($cfg['unix_socket'])
                ? sprintf('mysql:unix_socket=%s;dbname=%s;charset=utf8mb4', $cfg['unix_socket'], $cfg['dbname'] ?? '')
                : sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4', $cfg['host'] ?? 'localhost', (int)($cfg['port'] ?? 3306), $cfg['dbname'] ?? ''))
            : sprintf('pgsql:host=%s;port=%d;dbname=%s', $cfg['host'] ?? 'localhost', (int)($cfg['port'] ?? 5432), $cfg['dbname'] ?? '')
                . (isset($cfg['sslmode']) ? ';sslmode=' . $cfg['sslmode'] : '');

        return new \PDO($dsn, $cfg['username'], $cfg['password'], [
            \PDO::ATTR_PERSISTENT => $key,
            \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION,
            \PDO::ATTR_EMULATE_PREPARES => false,
        ]);
    }

    /**
     * The engine block ConnectionFactory reads, taken from the test DSN.
     *
     * @return array<string, mixed>
     */
    private function configFor(string $type): array
    {
        if (!TestEngine::provider()->available($type)) {
            self::markTestSkipped('GRAVDBKIT_TEST_' . strtoupper($type) . '_DSN not set');
        }

        $prefix = 'GRAVDBKIT_TEST_' . strtoupper($type);
        $dsn = (string)getenv($prefix . '_DSN');
        $cfg = [];
        foreach (explode(';', substr($dsn, (int)strpos($dsn, ':') + 1)) as $pair) {
            [$name, $value] = array_pad(explode('=', $pair, 2), 2, '');
            if (\in_array($name, ['host', 'port', 'unix_socket', 'dbname', 'sslmode'], true)) {
                $cfg[$name] = $value;
            }
        }

        return $cfg + [
            'username' => getenv($prefix . '_USER') ?: '',
            'password' => getenv($prefix . '_PASS') ?: '',
        ];
    }
}
