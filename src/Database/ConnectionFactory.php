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
 * Connection (savepoint names) and, for a persistent connection, supplies the
 * default persistent key.
 *
 * Persistent connections
 *
 * A MySQL, MariaDB or PostgreSQL config with `persistent` turned on keeps the server connection open in the PHP worker between requests (`\PDO::ATTR_PERSISTENT`), so the next request that asks for the same database skips the connect and authentication. It is off by default, and with it off the PDO is built exactly as it always was. Measured on PHP 8.4: a new PostgreSQL 16 connection cost about 6.7 ms on a benchmark server (1.2 ms over a local Unix socket), and turning persistence on raised a KahunaCart store's PostgreSQL capacity by 50 to 65 percent; MariaDB connects cheaply and gains far less.
 *
 * The handle is filed under a key rather than `true`, so each plugin gets a handle of its own and nothing done here can reach a transaction another plugin has open on the same database. The key is `persistent_key` from the config when it is set, else KitOptions::$persistentKey, whose default is the namespace of this copy of the kit and so already differs between Strauss-prefixed plugins.
 *
 * A `type: connection` setup (a grav-plugin-database named connection) opens its own PDO and reaches the kit through fromPdo(), so none of this applies to it.
 *
 * A reused handle can carry over what the last user left on it. What was verified on PHP 8.4 with pdo_mysql and pdo_pgsql, and what is done about each:
 *
 *  - An open transaction. PDO rolls it back itself when the PDO object is freed, whether the request ended normally, called exit, hit max_execution_time or ran out of memory, and whether it was started with beginTransaction() or a bare BEGIN. The pickup reset rolls back anything still open as a second line of defence; inTransaction() answers from state the client library already has, so this costs nothing when there is nothing.
 *  - Session state. User variables, `SET` settings, temporary tables, MySQL GET_LOCK() locks and PostgreSQL session advisory locks all survive into the next request on both drivers, and pdo_mysql resets nothing. PostgreSQL gets `DISCARD ALL` on pickup, the reset pgbouncer uses. MySQL has no equivalent a PDO can send, so code on a persistent MySQL connection must not leave session state behind (the kit itself leaves none; its unit suite checks).
 *  - A dead connection, after a server restart or an idle timeout. pdo_mysql pings a reused handle and reconnects on its own. pdo_pgsql often does not notice until the first statement fails with "server closed the connection unexpectedly", and the pickup reset is that first statement, so a PDOException from the reset is answered by opening once more, which gives a fresh connection. A server that is really down fails the second time too, and that error is the one thrown.
 *
 * When a pickup is reset. The kit has no request object, so "the start of a request" is taken to mean: nothing this class handed out for the same handle is still alive in this process. Under PHP-FPM every object from the last request is gone when the next one starts, so the first pickup in a request is reset. A second call in the same request while the first Connection (or a statement or the PDO taken from it) is still held is not reset, because anything open on the handle belongs to code that is still running; it gets the Connection already open when its KitOptions are equal, or a new Connection over the same PDO when they differ, never a second PDO object (freeing any PDO object on a persistent handle rolls back the handle's open transaction, so a second one would undo the first caller's work when it went out of scope). In a long-running CLI process, such as the job daemon, each pickup after the previous Connection was released is reset. An object kept alive by a reference cycle counts as still in use until PHP collects it, which only ever skips a reset, never runs one on work in progress. Because a reset only happens when no Connection holds the handle, no Connection ever has a transaction or savepoint depth that the reset makes false.
 *
 * SQLite never uses a persistent handle; see sqlite().
 */
final class ConnectionFactory
{
    private const PDO_OPTIONS = [
        \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION,
        \PDO::ATTR_DEFAULT_FETCH_MODE => \PDO::FETCH_ASSOC,
        \PDO::ATTR_EMULATE_PREPARES => false,
    ];

    /**
     * The persistent PDO and Connection last handed out in this process for each handle, held weakly so they drop out once freed. Static, so PHP-FPM empties it between requests; each Strauss-prefixed copy of the kit has its own.
     *
     * @var array<string, array{pdo: \WeakReference<\PDO>, connection: \WeakReference<Connection>}>
     */
    private static array $persistentHandles = [];

    /**
     * A top-level `persistent` (and `persistent_key`) applies to whichever server engine `type` selects, so a plugin can put one toggle in its `database` block; the same key inside the engine's own block wins over it. SQLite ignores both.
     *
     * @param array{type?: string, persistent?: bool|int|string, persistent_key?: string, sqlite?: array, mysql?: array, pgsql?: array} $config
     */
    public static function create(array $config, ?KitOptions $options = null): Connection
    {
        $type = $config['type'] ?? 'sqlite';

        return match ($type) {
            'sqlite' => self::sqlite((string)($config['sqlite']['path'] ?? ''), $options),
            'mysql' => self::mysql(self::engineConfig($config, 'mysql'), $options),
            'pgsql' => self::pgsql(self::engineConfig($config, 'pgsql'), $options),
            default => throw new \InvalidArgumentException("Unsupported database type: {$type}"),
        };
    }

    /**
     * SQLite with WAL, foreign keys, a five-second busy timeout and
     * synchronous=NORMAL. The directory is created when missing and given
     * deny-all `.htaccess` and empty `index.html` files, because stock Grav web
     * server rules do not block a `.sqlite` download from under `user/`.
     *
     * Never persistent, whatever the config says. Opening the file is cheap next to a server handshake, and a handle kept open across requests keeps the file it opened, not the path: a backup restored over the file, or a reset that replaces it, would leave every PHP-FPM worker reading and writing the old file until the workers restart. An open handle also keeps the WAL alive, so a database copied into place with the site idle (safe today, because the last connection to close checkpoints and removes the WAL) could be mixed with pages from the old WAL.
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
     * `persistent` keeps the connection open between requests (see the class docblock).
     *
     * @param array{host?: string, port?: int|string, unix_socket?: string, dbname?: string, username?: string, password?: string, persistent?: bool|int|string, persistent_key?: string} $cfg
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

        if (self::persistent($cfg)) {
            return self::pickUp('mysql', $dsn, $cfg, $options);
        }

        $pdo = new \PDO($dsn, $cfg['username'] ?? '', $cfg['password'] ?? '', self::PDO_OPTIONS);

        return new Connection($pdo, new MysqlDialect(), $options);
    }

    /**
     * `sslmode` is passed on only when it is one of libpq's six values.
     * `persistent` keeps the connection open between requests (see the class docblock).
     *
     * @param array{host?: string, port?: int|string, dbname?: string, username?: string, password?: string, sslmode?: string, persistent?: bool|int|string, persistent_key?: string} $cfg
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

        if (self::persistent($cfg)) {
            return self::pickUp('pgsql', $dsn, $cfg, $options);
        }

        $pdo = new \PDO($dsn, $cfg['username'] ?? '', $cfg['password'] ?? '', self::PDO_OPTIONS);

        return new Connection($pdo, new PgsqlDialect(), $options);
    }

    /**
     * Wrap an externally created PDO (a grav-plugin-database named connection,
     * or a test fixture). Applies the same attribute/pragma contract, except
     * the SQLite journal mode, which belongs to whoever opened the file.
     * Whether that PDO is persistent is up to whoever opened it; no pickup reset runs here.
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
     * The engine's block of a create() config, with a top-level `persistent` or `persistent_key` filled in when the block does not set its own.
     *
     * @param array<string, mixed> $config
     */
    private static function engineConfig(array $config, string $type): mixed
    {
        $cfg = $config[$type] ?? [];
        if (!\is_array($cfg)) {
            return $cfg;
        }

        foreach (['persistent', 'persistent_key'] as $name) {
            if (\array_key_exists($name, $config) && !\array_key_exists($name, $cfg)) {
                $cfg[$name] = $config[$name];
            }
        }

        return $cfg;
    }

    /**
     * Whether an engine block turns persistence on. Read as a boolean, so a toggle saved as `true`, `1`, `'1'` or `'on'` turns it on and `false`, `0`, `'0'`, `''` or `'off'` leaves it off.
     *
     * @param array<string, mixed> $cfg
     */
    private static function persistent(array $cfg): bool
    {
        return filter_var($cfg['persistent'] ?? false, \FILTER_VALIDATE_BOOL);
    }

    /**
     * The Connection on the persistent handle for this DSN, user, password and key.
     *
     * While something in this process still holds the handle, the caller gets what is already open rather than a second PDO object: freeing any PDO object on a persistent handle rolls back whatever transaction the handle has open, so a second object dropped by one piece of code would silently undo another's work in progress. The live Connection itself comes back when the caller's KitOptions match, which keeps one transaction and savepoint depth per server session; different options get a new Connection over the same PDO. Only when nothing holds the handle is a new PDO opened and reset (see the class docblock).
     */
    private static function pickUp(string $type, string $dsn, array $cfg, ?KitOptions $options): Connection
    {
        $options ??= new KitOptions();
        $username = $cfg['username'] ?? '';
        $password = $cfg['password'] ?? '';
        $key = isset($cfg['persistent_key']) && $cfg['persistent_key'] !== ''
            ? KitOptions::checkPersistentKey((string)$cfg['persistent_key'])
            : $options->persistentKey;
        $handle = hash('xxh128', $dsn . "\0" . $username . "\0" . $password . "\0" . $key);

        $held = self::$persistentHandles[$handle] ?? null;
        $pdo = $held === null ? null : $held['pdo']->get();
        if ($pdo !== null) {
            $connection = $held['connection']->get();
            if ($connection !== null && $connection->options() == $options) {
                return $connection;
            }

            $wrapper = new Connection($pdo, self::dialectFor($type), $options);

            // The first Connection stays the one equal options get back while it lives.
            return $connection === null ? self::remember($handle, $pdo, $wrapper) : $wrapper;
        }

        $attributes = [\PDO::ATTR_PERSISTENT => $key] + self::PDO_OPTIONS;
        $pdo = new \PDO($dsn, $username, $password, $attributes);

        try {
            self::resetHandle($pdo, $type);
        } catch (\PDOException) {
            // Most likely a connection the server closed while it sat idle. Freeing the object is what lets PDO see the broken handle and open a fresh one on the next constructor call. Tried once.
            $pdo = null;
            $pdo = new \PDO($dsn, $username, $password, $attributes);
            self::resetHandle($pdo, $type);
        }

        return self::remember($handle, $pdo, new Connection($pdo, self::dialectFor($type), $options));
    }

    private static function remember(string $handle, \PDO $pdo, Connection $connection): Connection
    {
        foreach (self::$persistentHandles as $key => $held) {
            if ($held['pdo']->get() === null) {
                unset(self::$persistentHandles[$key]);
            }
        }

        self::$persistentHandles[$handle] = [
            'pdo' => \WeakReference::create($pdo),
            'connection' => \WeakReference::create($connection),
        ];

        return $connection;
    }

    /**
     * Undo what an earlier user left on a persistent handle: roll back an open transaction and, on PostgreSQL, `DISCARD ALL`. A PDOException here usually means the connection is dead, which is how pickUp() finds out.
     */
    private static function resetHandle(\PDO $pdo, string $type): void
    {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }

        if ($type === 'pgsql') {
            $pdo->exec('DISCARD ALL');
        }
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
