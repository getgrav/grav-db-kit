<?php

declare(strict_types=1);

namespace TrilbyMedia\GravDbKit\Database;

use TrilbyMedia\GravDbKit\Database\Dialect\Dialect;
use TrilbyMedia\GravDbKit\Database\Dialect\MysqlDialect;
use TrilbyMedia\GravDbKit\Database\Dialect\PgsqlDialect;
use TrilbyMedia\GravDbKit\Database\Dialect\SqliteDialect;

/**
 * Builds connections with each engine's required init contract applied.
 * Grav-free: paths must arrive already resolved (no stream URIs).
 *
 * Every builder takes an optional KitOptions, which is handed to the
 * Connection (savepoint names) and nowhere else.
 */
final class ConnectionFactory
{
    private const PDO_OPTIONS = [
        \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION,
        \PDO::ATTR_DEFAULT_FETCH_MODE => \PDO::FETCH_ASSOC,
        \PDO::ATTR_EMULATE_PREPARES => false,
    ];

    /**
     * @param array{type?: string, sqlite?: array, mysql?: array, pgsql?: array} $config
     */
    public static function create(array $config, ?KitOptions $options = null): Connection
    {
        $type = $config['type'] ?? 'sqlite';

        return match ($type) {
            'sqlite' => self::sqlite((string)($config['sqlite']['path'] ?? ''), $options),
            'mysql' => self::mysql($config['mysql'] ?? [], $options),
            'pgsql' => self::pgsql($config['pgsql'] ?? [], $options),
            default => throw new \InvalidArgumentException("Unsupported database type: {$type}"),
        };
    }

    /**
     * SQLite with WAL, foreign keys, a five-second busy timeout and
     * synchronous=NORMAL. The directory is created when missing and given
     * deny-all `.htaccess` and empty `index.html` files, because stock Grav web
     * server rules do not block a `.sqlite` download from under `user/`.
     */
    public static function sqlite(string $path, ?KitOptions $options = null): Connection
    {
        if ($path === '') {
            throw new \InvalidArgumentException('SQLite database path is empty');
        }

        if ($path !== ':memory:') {
            self::prepareSqliteDirectory(\dirname($path));
        }

        $pdo = new \PDO('sqlite:' . $path, null, null, self::PDO_OPTIONS);

        if ($path !== ':memory:') {
            $pdo->exec('PRAGMA journal_mode=WAL');
            @chmod($path, 0640);
        }
        $pdo->exec('PRAGMA foreign_keys=ON');
        $pdo->exec('PRAGMA busy_timeout=5000');
        $pdo->exec('PRAGMA synchronous=NORMAL');
        $pdo->exec('PRAGMA temp_store=MEMORY');

        return new Connection($pdo, new SqliteDialect(), $options);
    }

    /**
     * `unix_socket` wins over host and port when it is given, which is how a
     * local MariaDB that authenticates the OS user over its socket is reached.
     *
     * @param array{host?: string, port?: int|string, unix_socket?: string, dbname?: string, username?: string, password?: string} $cfg
     */
    public static function mysql(array $cfg, ?KitOptions $options = null): Connection
    {
        $socket = (string)($cfg['unix_socket'] ?? '');
        $dsn = $socket !== ''
            ? sprintf('mysql:unix_socket=%s;dbname=%s;charset=utf8mb4', $socket, $cfg['dbname'] ?? '')
            : sprintf(
                'mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4',
                $cfg['host'] ?? 'localhost',
                (int)($cfg['port'] ?? 3306),
                $cfg['dbname'] ?? ''
            );

        $pdo = new \PDO($dsn, $cfg['username'] ?? '', $cfg['password'] ?? '', self::PDO_OPTIONS);

        return new Connection($pdo, new MysqlDialect(), $options);
    }

    /**
     * @param array{host?: string, port?: int|string, dbname?: string, username?: string, password?: string, sslmode?: string} $cfg
     */
    public static function pgsql(array $cfg, ?KitOptions $options = null): Connection
    {
        $dsn = sprintf(
            'pgsql:host=%s;port=%d;dbname=%s',
            $cfg['host'] ?? 'localhost',
            (int)($cfg['port'] ?? 5432),
            $cfg['dbname'] ?? ''
        );

        $sslmode = $cfg['sslmode'] ?? null;
        if (\is_string($sslmode)
            && \in_array($sslmode, ['disable', 'allow', 'prefer', 'require', 'verify-ca', 'verify-full'], true)) {
            $dsn .= ';sslmode=' . $sslmode;
        }

        $pdo = new \PDO($dsn, $cfg['username'] ?? '', $cfg['password'] ?? '', self::PDO_OPTIONS);

        return new Connection($pdo, new PgsqlDialect(), $options);
    }

    /**
     * Wrap an externally created PDO (a grav-plugin-database named connection,
     * or a test fixture). Applies the same attribute/pragma contract, except
     * the SQLite journal mode, which belongs to whoever opened the file.
     */
    public static function fromPdo(\PDO $pdo, string $type, ?KitOptions $options = null): Connection
    {
        $pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        $pdo->setAttribute(\PDO::ATTR_DEFAULT_FETCH_MODE, \PDO::FETCH_ASSOC);

        $dialect = self::dialectFor($type);
        if ($dialect instanceof SqliteDialect) {
            $pdo->exec('PRAGMA foreign_keys=ON');
            $pdo->exec('PRAGMA busy_timeout=5000');
        }

        return new Connection($pdo, $dialect, $options);
    }

    public static function dialectFor(string $type): Dialect
    {
        return match ($type) {
            'sqlite' => new SqliteDialect(),
            'mysql' => new MysqlDialect(),
            'pgsql' => new PgsqlDialect(),
            default => throw new \InvalidArgumentException("Unsupported database type: {$type}"),
        };
    }

    /**
     * MySQL servers without strict mode silently truncate data; we assume
     * strict and warn (status page) rather than fight it.
     */
    public static function mysqlIsStrict(Connection $connection): bool
    {
        if ($connection->dialect()->name() !== 'mysql') {
            return true;
        }

        $mode = (string)$connection->fetchValue('SELECT @@SESSION.sql_mode');

        return str_contains($mode, 'STRICT_TRANS_TABLES') || str_contains($mode, 'STRICT_ALL_TABLES');
    }

    /**
     * The DB directory gets deny-all protection files because stock Grav
     * Apache/nginx rules do not block .sqlite downloads under user/.
     */
    private static function prepareSqliteDirectory(string $dir): void
    {
        if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
            throw new \RuntimeException("Unable to create database directory: {$dir}");
        }

        $htaccess = $dir . '/.htaccess';
        if (!file_exists($htaccess)) {
            file_put_contents(
                $htaccess,
                "<IfModule mod_authz_core.c>\n    Require all denied\n</IfModule>\n" .
                "<IfModule !mod_authz_core.c>\n    Order deny,allow\n    Deny from all\n</IfModule>\n"
            );
        }

        $index = $dir . '/index.html';
        if (!file_exists($index)) {
            file_put_contents($index, '');
        }
    }
}
