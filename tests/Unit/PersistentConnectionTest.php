<?php

declare(strict_types=1);

namespace TrilbyMedia\GravDbKit\Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use TrilbyMedia\GravDbKit\Database\ConnectionFactory;
use TrilbyMedia\GravDbKit\Database\KitOptions;

/**
 * Persistent connections, the parts that need no database server.
 *
 * The engine half (reuse, the pickup reset, a killed backend, handle separation) is tests/Integration/PersistentConnectionTest.php.
 *
 * The other thing held here is the promise the MySQL side rests on. pdo_mysql hands a reused connection back with whatever session state the last user left on it, and nothing the kit can send resets that, so no SQL in the kit may create any: no session variables or settings, no GET_LOCK(), no session-level advisory locks, no temporary tables, no LOCK TABLES.
 */
final class PersistentConnectionTest extends TestCase
{
    public function testTheDefaultKeyIsTheNamespaceOfThisKitCopy(): void
    {
        // Strauss rewrites the namespace in every plugin's copy, so the default already differs per plugin.
        self::assertSame('TrilbyMedia\\GravDbKit\\Database', (new KitOptions())->persistentKey);
        self::assertSame('forum-pro', (new KitOptions(persistentKey: 'forum-pro'))->persistentKey);
    }

    /** @return array<string, array{string}> */
    public static function badKeys(): array
    {
        return [
            'empty' => [''],
            // PDO reads a numeric key as on/off and would share the handle with every `true` caller.
            'numeric' => ['1'],
            'float' => ['2.5'],
            'nul byte' => ["kahuna\0cart"],
        ];
    }

    #[DataProvider('badKeys')]
    public function testAKeyPdoWouldNotKeepApartIsRefused(string $key): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new KitOptions(persistentKey: $key);
    }

    /** A bad `persistent_key` in the config is refused before anything connects. */
    public function testABadPersistentKeyInTheConfigIsRefused(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Invalid persistent key: 1');

        ConnectionFactory::create([
            'type' => 'pgsql',
            'persistent' => true,
            'persistent_key' => '1',
            'pgsql' => ['host' => '127.0.0.1', 'port' => 1, 'dbname' => 'nothing'],
        ]);
    }

    /** SQLite is opened the ordinary way whatever the config says; see ConnectionFactory::sqlite(). */
    public function testSqliteIgnoresTheOption(): void
    {
        $dir = sys_get_temp_dir() . '/gravdbkit-persistent-' . bin2hex(random_bytes(4));

        try {
            $db = ConnectionFactory::create([
                'type' => 'sqlite',
                'persistent' => true,
                'sqlite' => ['path' => $dir . '/test.sqlite', 'persistent' => true],
            ], new KitOptions(persistentKey: 'kit-test'));

            self::assertFalse((bool)$db->pdo()->getAttribute(\PDO::ATTR_PERSISTENT));
            self::assertSame('wal', strtolower((string)$db->fetchValue('PRAGMA journal_mode')));
            unset($db);
        } finally {
            array_map('unlink', array_merge(glob($dir . '/*') ?: [], glob($dir . '/.htaccess') ?: []));
            @rmdir($dir);
        }
    }

    public function testNoKitSqlLeavesSessionStateOnAConnection(): void
    {
        $root = \dirname(__DIR__, 2) . '/src';
        $pattern = '/\bGET_LOCK\s*\(|\bpg_(?:try_)?advisory_lock(?:_shared)?\s*\('
            . '|\bSET\s+(?:SESSION\b|GLOBAL\b|@|time_zone\s*=|search_path\s*(?:=|TO\b)|NAMES\b|TIME\s+ZONE\b|sql_mode\s*=|ROLE\b)'
            . '|\bCREATE\s+(?:GLOBAL\s+|LOCAL\s+)?TEMP(?:ORARY)?\s+TABLE\b|\bLOCK\s+TABLES\b|\bLISTEN\s+\w/i';

        $files = [];
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS));
        foreach ($iterator as $file) {
            if ($file->getExtension() === 'php') {
                $files[] = $file->getPathname();
            }
        }
        self::assertGreaterThan(30, \count($files));

        $found = [];
        foreach ($files as $path) {
            foreach (token_get_all((string)file_get_contents($path)) as $token) {
                if (\is_array($token)
                    && ($token[0] === T_CONSTANT_ENCAPSED_STRING || $token[0] === T_ENCAPSED_AND_WHITESPACE)
                    && preg_match($pattern, $token[1], $match)) {
                    $found[] = substr($path, \strlen($root) + 1) . ':' . $token[2] . ' ' . $match[0];
                }
            }
        }

        self::assertSame(
            [],
            $found,
            'SQL that leaves session state on a connection. On a persistent MySQL connection that state reaches the next request; see ConnectionFactory.'
        );
        // The pattern itself, so a typo in it cannot pass everything.
        self::assertMatchesRegularExpression($pattern, "SELECT GET_LOCK('x', 0)");
        self::assertMatchesRegularExpression($pattern, 'SET SESSION sql_mode = ""');
        self::assertMatchesRegularExpression($pattern, 'SELECT pg_advisory_lock(1)');
        self::assertMatchesRegularExpression($pattern, 'CREATE TEMPORARY TABLE t (id INT)');
        self::assertDoesNotMatchRegularExpression($pattern, 'SELECT pg_advisory_xact_lock(1)');
        self::assertDoesNotMatchRegularExpression($pattern, 'SET FOREIGN_KEY_CHECKS = 0');
    }
}
