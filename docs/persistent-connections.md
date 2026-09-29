# Persistent connections

A MySQL, MariaDB or PostgreSQL connection can stay open in the PHP worker between requests, so the next request that asks for the same database skips the connect and the authentication. It is PDO's `ATTR_PERSISTENT`, plus the checks the kit runs when it hands a kept connection back out. It is off by default, and a plugin turns it on per site with one config value.

## Who it is for

Sites on MySQL, MariaDB or PostgreSQL served by PHP-FPM (or mod_php). A PHP-FPM worker answers one request after another, so a connection it keeps is reused by the next request that worker serves.

What it saves depends on the engine. PostgreSQL forks a server process and authenticates it for every new connection: on a benchmark server (Ubuntu, PostgreSQL 16, PHP 8.4) a new connection cost about 6.7 ms, and turning persistence on raised a KahunaCart store's capacity by 50 to 65 percent (home page 187 to 306 requests a second, catalog 132 to 197). Over a local Unix socket the connect and first query went from 1.206 ms to 0.067 ms. MariaDB connects far more cheaply (0.059 to 0.031 ms locally), so it gains little.

It does nothing for SQLite, for a `type: connection` setup (a grav-plugin-database named connection, which opens its own PDO and reaches the kit through `ConnectionFactory::fromPdo()`), or for the CLI (the connection dies with the process anyway).

## The trade-off

- **Every worker holds a connection, even when idle.** Each plugin that turns this on keeps one connection per PHP-FPM worker, per database. The number of plugins using it, times the workers across every pool on every site that shares the server, plus the scheduler, the job daemon and anything else that connects, must fit under the server's `max_connections`. The defaults are 100 on PostgreSQL and 151 on MySQL and MariaDB. Past that limit, new connections are refused and requests fail.
- **Credential changes leave stale handles.** PDO files a kept connection under the DSN, user, password and key, so after a password or host change the next request opens a new connection, but the old one stays open in every worker until PHP-FPM restarts (or the server closes it as idle). Restart PHP-FPM after changing the database settings. A password changed on the server alone does not close sessions that already authenticated.
- **Session state carries over on MySQL.** pdo_mysql hands a kept connection back exactly as the last request left it. Code that runs on a persistent MySQL connection must not leave session state behind: no `SET @var` or `SET SESSION`, no `SET time_zone` or `SET NAMES`, no temporary tables, no `GET_LOCK()`, no `LOCK TABLES`. The kit itself leaves none, and its unit suite fails if that changes. PostgreSQL is reset with `DISCARD ALL` on pickup, so it has no such rule.

## Turning it on

`persistent` is read as a boolean from the config array a plugin already passes to `ConnectionFactory`:

```php
// top level, applies to whichever server engine `type` selects
ConnectionFactory::create(['type' => 'pgsql', 'persistent' => true, 'pgsql' => [...]], $options);

// or in the engine's own block, which wins over the top level
ConnectionFactory::create(['type' => 'mysql', 'mysql' => ['persistent' => true, ...]], $options);
ConnectionFactory::pgsql(['persistent' => true, ...], $options);
```

`true`, `1`, `'1'` and `'on'` turn it on; `false`, `0`, `'0'`, `''`, `'off'` or no key at all leave it off. With it off, the PDO is built exactly as before.

`sqlite()` ignores it on purpose. A handle kept open across requests keeps the file it opened, not the path: a backup restored over the file, or a demo reset that replaces it, would leave every worker reading and writing the old file until PHP-FPM restarts. An open handle also keeps the WAL alive, so a database copied into place while the site is idle (safe today, because the last connection to close checkpoints and removes the WAL) could be mixed with pages from the old WAL. And opening a SQLite file is cheap next to a server handshake.

## The persistent key

PDO hands one kept connection to everyone who opens the same DSN, user and password under the same key. The kit never uses PDO's shared default (`ATTR_PERSISTENT => true`); it uses a key, so each plugin gets a connection of its own and the reset described below can never reach a transaction another plugin has open on the same database.

The key is, in order:

1. `persistent_key` from the config (top level or engine block), when it is set;
2. otherwise `KitOptions::$persistentKey`;
3. whose default is the namespace of the copy of the kit that builds the connection. Strauss rewrites that namespace in every plugin (`Grav\Plugin\ForumPro\Vendor\TrilbyMedia\GravDbKit\Database` in Forum Pro's copy), so two plugins that set nothing still get separate connections. An unprefixed copy answers `TrilbyMedia\GravDbKit\Database`.

Each plugin should still set its own, usually its slug, in the `KitOptions` it already builds: `new KitOptions(savepointPrefix: 'fp_sp_', persistentKey: 'forum-pro')`. An empty or numeric key is refused, because PDO reads a numeric key as plain on/off and would share the connection with every `true` caller.

## What happens on pickup

What a kept connection can carry over, as verified on PHP 8.4 with pdo_mysql and pdo_pgsql, and what the kit does about it:

- **An open transaction.** PDO rolls it back itself when the PDO object is freed, whether the request ended normally, called `exit`, hit `max_execution_time` or ran out of memory, and whether the transaction started with `beginTransaction()` or a bare `BEGIN`. The kit rolls back anything still open on pickup as a second line of defence. `inTransaction()` answers from state the client library already has, so this costs no round trip.
- **Session state.** On PostgreSQL the pickup sends `DISCARD ALL` (the reset pgbouncer uses), which drops `SET` values, temporary tables, prepared statements and session advisory locks. MySQL has no equivalent a PDO can send; see the trade-off above.
- **A dead connection.** After a server restart or an idle timeout, pdo_mysql pings the kept handle and reconnects on its own. pdo_pgsql often does not notice until the first statement fails with "server closed the connection unexpectedly"; the pickup reset is that first statement, so a `PDOException` from the reset is answered by opening once more, which gives a fresh connection. A server that is really down fails the second time too, and that error is what the caller sees.

### When a pickup is reset

The kit has no request object, so "the start of a request" means: nothing `ConnectionFactory` handed out for the same connection (the `Connection`, its PDO, or a statement from it) is still alive in this process.

- Under PHP-FPM every object from the last request is gone when the next one starts, so the first pickup in a request is reset.
- A second `ConnectionFactory` call in the same request, while the first `Connection` is still held, is not reset, because anything open on the connection belongs to code that is still running. It gets the same `Connection` object back when its `KitOptions` are equal, so a `transaction()` inside another one still becomes a savepoint, or a new `Connection` over the same PDO when the options differ. It never gets a second PDO object: freeing any PDO object on a kept connection rolls back that connection's open transaction, so a second one would undo the first caller's work the moment it went out of scope.
- In a long-running CLI process, such as the job daemon, each pickup after the previous `Connection` was released is reset.
- An object kept alive by a reference cycle counts as in use until PHP collects it. That can only skip a reset, never run one on work in progress.

Because a reset only happens when no `Connection` holds the connection, no `Connection`'s transaction or savepoint depth is ever made wrong by it.

## For plugin authors

A plugin whose `connection()` passes its `database` config block to `ConnectionFactory::create()` needs no code to adopt this: a blueprint field, a `persistent: false` default in the plugin's YAML, and a kit that is at least 1.0.3. Setting `persistentKey` in the plugin's `KitOptions` is recommended but optional (see the key above).

Plugin YAML:

```yaml
database:
  type: sqlite
  persistent: false
  # ...
```

Blueprint field, next to the other database fields. Move the label and help into the plugin's `languages.yaml` as usual:

```yaml
            database.persistent:
              type: toggle
              label: Persistent Connections
              help: "Only for MySQL, MariaDB or PostgreSQL served by PHP-FPM; no effect on SQLite or on a named connection. Each PHP-FPM worker keeps this plugin's database connection open between requests instead of opening a new one every time, which saves several milliseconds a request on PostgreSQL. Every plugin that turns this on holds one connection per worker, so the number of those plugins times the PHP-FPM workers across every pool, plus the scheduler and anything else that connects, must fit under the database's max_connections (PostgreSQL defaults to 100, MySQL and MariaDB to 151). Restart PHP-FPM after changing the database settings."
              highlight: 1
              default: 0
              options:
                1: PLUGIN_ADMIN.ENABLED
                0: PLUGIN_ADMIN.DISABLED
              validate:
                type: bool
```

The field is always shown rather than hidden for SQLite. Admin Next's `conditional` container only compares a field with one value (so it cannot say "mysql or pgsql"), and Mailroom's blueprint records that the API plugin mis-keys a conditional container's children. The help text says where the setting has no effect instead.

Before turning it on for a MySQL site, check that the plugin's own SQL leaves no session state (see the trade-off above). KahunaCart keeps a unit test that token-scans its SQL for the patterns in the kit's `tests/Unit/PersistentConnectionTest.php`; copying that test is the cheapest way to keep the promise.
