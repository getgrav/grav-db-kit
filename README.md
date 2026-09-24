# grav-db-kit

Shared database, migration, job queue and event infrastructure for Grav plugins by Trilby Media: a PDO wrapper with SQLite, MySQL and PostgreSQL dialects, an upgrade-only migrator, a database-backed job queue with an inline drain, an event sink backbone, a rate limiter and a key/value store.

It is a Composer package, not a Grav plugin, and it has no Grav dependency. Each plugin bundles its own copy, namespace-prefixed with Strauss, so plugins update it in their own releases and never need another plugin installed.

The code is extracted from KahunaCart's newer database layer and Forum Pro's services. Both plugins still ship their in-tree copies; see [Switching an existing plugin](#switching-an-existing-plugin) for what changes when they move to the kit.

## Requirements

- PHP 8.3 or newer with `ext-pdo`
- `pdo_sqlite` for SQLite (the default engine), `pdo_mysql` for MySQL 8+ / MariaDB 10.6+, `pdo_pgsql` for PostgreSQL 14+

```
composer require getgrav/grav-db-kit
```

Until the package is on Packagist, plugins use a Composer path repository pointing at `../grav-db-kit`.

## What is in it

| Namespace | Classes |
|---|---|
| `TrilbyMedia\GravDbKit\Database` | `Connection`, `ConnectionFactory`, `Dialect\{Dialect, AbstractDialect, SqliteDialect, MysqlDialect, PgsqlDialect}`, `Migration`, `Migrator`, `KitTables`, `KitOptions`, `Lease`, `LeaseUnavailable`, `SchemaGuard`, `SchemaStateStore`, `KvSchemaState`, `CallbackSchemaState` |
| `TrilbyMedia\GravDbKit\Schema` | `InfraTables` |
| `TrilbyMedia\GravDbKit\Support` | `Clock`, `SystemClock`, `KvStore`, `RateLimiter`, `RateLimitResult`, `UnsubscribeSigner` |
| `TrilbyMedia\GravDbKit\Events` | `EventSink`, `CompositeSink`, `NullSink` |
| `TrilbyMedia\GravDbKit\Jobs` | the job queue (ported separately from KahunaCart's `classes/Jobs`) |
| `TrilbyMedia\GravDbKit\Testing` | `MigratedDatabase`, `EngineProvider`, `SpySink`, `FrozenClock` |

The `Testing` classes ship in the package (they have no PHPUnit dependency) so plugin test suites can use them.

## Connections

```php
use TrilbyMedia\GravDbKit\Database\ConnectionFactory;
use TrilbyMedia\GravDbKit\Database\KitOptions;

$db = ConnectionFactory::sqlite('/path/to/user/data/helpdesk-pro/db/helpdesk.sqlite', new KitOptions(savepointPrefix: 'hd_sp_'));

// or from a plugin config array: ['type' => 'sqlite'|'mysql'|'pgsql', 'sqlite' => ['path' => …], 'mysql' => […], 'pgsql' => […]]
$db = ConnectionFactory::create($config['database'], $options);
```

`ConnectionFactory::sqlite()` turns on WAL, `foreign_keys`, `busy_timeout=5000` and `synchronous=NORMAL`, creates the directory when it is missing and puts a deny-all `.htaccess` and an empty `index.html` in it, because stock Grav web server rules do not block a `.sqlite` download from under `user/`. `mysql()` takes `host`/`port` or `unix_socket`, plus `dbname`, `username` and `password`. `pgsql()` also takes `sslmode`. `fromPdo($pdo, 'sqlite'|'mysql'|'pgsql')` wraps a PDO someone else opened (a grav-plugin-database named connection, a test fixture). `mysqlIsStrict($db)` tells a status page whether the server silently truncates data.

`Connection` is a thin PDO wrapper:

| Method | Does |
|---|---|
| `run($sql, $params)` | Prepare and execute; returns the `PDOStatement` |
| `fetchAll`, `fetchRow`, `fetchValue` | Read helpers (`fetchRow`/`fetchValue` return `null` for no row) |
| `execute($sql, $params)` | Affected row count |
| `insert($table, $row)` | Insert and return the generated `id` (Postgres uses `RETURNING id`, so the table needs an `id` column; use `run()` or `upsert()` for tables keyed otherwise) |
| `insertMany($table, $columns, $rows)` | Bulk insert with supplied keys, chunked under each engine's bind-parameter limit |
| `syncIdentitySequence($table)` | Move Postgres' identity sequence past a bulk load (a no-op elsewhere) |
| `upsert($table, $row, $conflictColumns, $updateColumns)` | Insert or update on a unique key; empty `$updateColumns` means insert-if-absent |
| `update($table, $values, $where, $params)`, `delete($table, $where, $params)` | Affected row count |
| `isUniqueViolation(PDOException)` | Whether the engine is saying "the row is already there", as opposed to a deadlock or any other error |
| `transaction(fn (Connection $c) => …)` | Atomic; nested calls become savepoints; the outermost call retries up to three times on SQLite busy, MySQL deadlock/lock-wait and Postgres serialization/deadlock errors, so the callback must be safe to run again |
| `inTransaction()`, `dialect()`, `pdo()`, `options()` | Accessors |

Runtime SQL written against a `Connection` has to stay in the portable subset the three engines share: identifiers unquoted (validated names) or through `Dialect::quoteIdentifier()`, every value bound as a parameter, `LIMIT`/`OFFSET` on `SELECT` only, no `RETURNING`/`ON CONFLICT`/`ON DUPLICATE` outside the dialect, times as UTC epoch `BIGINT`, booleans as `INTEGER` 0/1.

The dialect gives migrations their DDL fragments (`primaryKey()`, `textLong()`, `tableOptions()`) and idempotency probes (`tableExists`, `columnExists`, `createIndexIfMissing`, `dropIndexIfExists`, `renameTableIfExists`), and gives `Connection` its engine-specific writes and error classification (`isRetryableError`, `isUniqueViolation`).

## Table names and options

Nothing in the kit writes a table name inline. `KitTables` names every table the kit itself touches:

```php
use TrilbyMedia\GravDbKit\Database\KitTables;

$tables = KitTables::withPrefix('helpdesk');
// helpdesk_migrations (+ constraint uq_helpdesk_migrations), helpdesk_locks, helpdesk_kv, helpdesk_jobs, helpdesk_rate_limits

$tables = new KitTables(migrations: 'forum_migrations', locks: 'forum_locks', kv: 'forum_kv');
```

| Property | Default | Used by |
|---|---|---|
| `migrations` | `kit_migrations` | `Migrator` tracking table |
| `migrationsUnique` | `uq_` + `migrations` | the `UNIQUE (migration, step)` constraint the migrator's bootstrap creates |
| `locks` | `kit_locks` | `Lease` (and so the migrator's run-lock) |
| `kv` | `kit_kv` | `KvStore`, `KvSchemaState` |
| `jobs` | `kit_jobs` | the job queue |
| `rateLimits` | `kit_rate_limits` | `RateLimiter` |

Every name is validated as a plain SQL identifier when the object is built. `toArray()` lists them.

`KitOptions` holds the rest:

| Property | Default | Meaning |
|---|---|---|
| `savepointPrefix` | `sp_` | Nested transactions are savepoints `{prefix}1`, `{prefix}2`… (Forum Pro used `fp_sp_`, KahunaCart `cp_sp_`) |
| `lockOwner` | `null` (host and pid) | What a lease row says about who holds it; `owner()` resolves it |
| `lockTtl` | `600` | Default lease length in seconds, and the migration lock's |

Pass the same `KitOptions` to `ConnectionFactory` and every class that takes one. Classes that take a `Connection` and no options use the connection's.

## Migrations

A migration is a file named `NNNN_snake_name.php` that returns an instance of `Migration`:

```php
use TrilbyMedia\GravDbKit\Database\Connection;
use TrilbyMedia\GravDbKit\Database\Dialect\Dialect;
use TrilbyMedia\GravDbKit\Database\KitTables;
use TrilbyMedia\GravDbKit\Database\Migration;
use TrilbyMedia\GravDbKit\Schema\InfraTables;

return new class implements Migration {
    public function name(): string
    {
        return '0001_infra';
    }

    public function steps(Dialect $d): array
    {
        $t = KitTables::withPrefix('helpdesk');

        return [
            'create_helpdesk_kv' => InfraTables::kv($d, $t),
            'create_helpdesk_jobs' => InfraTables::jobs($d, $t),
            'create_helpdesk_rate_limits' => InfraTables::rateLimits($d, $t),
            'create_helpdesk_people' => function (Connection $c) use ($d): void {
                if (!$d->tableExists($c->pdo(), 'helpdesk_people')) {
                    $c->run("CREATE TABLE helpdesk_people (id {$d->primaryKey()}, …) {$d->tableOptions()}");
                }
            },
        ];
    }
};
```

Steps run in array order and are recorded one at a time (MySQL commits DDL implicitly, so a crash can land between steps), which is why every step has to be idempotent.

```php
use TrilbyMedia\GravDbKit\Database\Migrator;

$migrator = new Migrator($db, [$basePath, ...$addonPaths], $tables, $options);
$migrator->pending();    // ['0002_people' => ['create_helpdesk_people', …], …]
$migrator->isUpToDate();
$migrator->migrate(fn (string $migration, string $step) => print("{$migration}:{$step}\n"));
```

- Upgrade only: there are no down migrations; recovery is a backup.
- Several directories run in the order given, files sorted by name within each. That is how an add-on brings its tables into the same database and tracking table. Two files claiming one migration name is an error, and a missing directory contributes nothing.
- `bootstrap()` creates the migrations and locks tables with `CREATE TABLE IF NOT EXISTS`, so it is harmless on a database that already has them.
- `migrate()` holds a `Lease` named `migrate` for `KitOptions::$lockTtl` seconds and throws when another process holds it.
- `Migrator::fingerprint($paths)` is the name, size and mtime of every migration file, without reading any of them.
- Each migration file is `require`d once per process and remembered by path, mtime and size. Migration files are anonymous classes, and PHP never frees a compiled anonymous class, so a process that builds many migrators (a test suite, a long worker) would otherwise grow by every migration's compiled code each time. `Migrator::forgetLoadedFiles()` clears the cache.

## SchemaGuard

`SchemaGuard::ensure(Migrator $migrator, SchemaStateStore $state, string $policy): array` is the per-request "is the schema current?" check. It returns `['policy' => …, 'pending' => int, 'applied' => int]`.

When the store remembers the current fingerprint, it answers at once with nothing pending. Otherwise it asks the migrator, applies pending steps if the policy allows, and records the fingerprint once nothing is pending. Adding, removing or editing a migration file, or an add-on bringing its directory in, changes the fingerprint, so the full check runs once more.

| Policy | Applies pending steps |
|---|---|
| `auto` | on every engine |
| `sqlite` | only on SQLite; server databases are migrated from the CLI |
| `sqlite-only` | same as `sqlite` (Forum Pro's spelling) |
| `manual` | never; pending steps are only counted |

An unknown policy throws `InvalidArgumentException` rather than quietly behaving like `manual`. `SchemaGuard::normalizePolicy()` and `SchemaGuard::allows($policy, $engine)` are public for plugins that want to check a setting early.

Two stores:

- `new KvSchemaState(new KvStore($db, $tables), 'migrations.complete')` keeps the fingerprint in the plugin's KV table (Forum Pro's arrangement). The answer lives in the database it describes, and a missing KV table on a fresh database reads as "not recorded".
- `new CallbackSchemaState($get, $set)` hands it to two closures (KahunaCart keeps it in Grav's cache so `bin/grav clear` forces a recheck). Fold the database target into the cache key, or a site pointed at a fresh database would trust an answer about the old one.

Call `ensure()` outside any open transaction.

## InfraTables

`InfraTables::kv|jobs|rateLimits(Dialect $d, KitTables $t)` return migration step closures that create the tables the kit's classes use, under the names in `KitTables`:

- `kv`: `kv_key VARCHAR(64)` primary key, `kv_value TEXT NULL`, `updated_at BIGINT`.
- `jobs`: `id`, `type`, `payload_json`, `run_after`, `attempts`, `max_attempts`, `locked_at`, `locked_by`, `completed_at`, `last_error`, `created_at`, `cancel_requested_at`, `cancelled_at`, `dedupe_key`, with indexes `ix_{jobs}_pending (completed_at, run_after)` and `ix_{jobs}_dedupe (dedupe_key, completed_at)`. This is KahunaCart's `kahunacart_jobs` after its migrations 0001, 0049 and 0077, without the store scope. On a table that already exists in an older layout it adds the missing columns and indexes in place.
- `rateLimits`: `id`, `bucket VARCHAR(64)`, `rl_key VARCHAR(190)`, `window_start BIGINT`, `hits`, `UNIQUE (bucket, rl_key, window_start)` and `ix_{table}_window (window_start)`. An existing table in another layout is left alone (see switching).

The migrations and locks tables are not here, because the migrator creates them before it runs anything.

## Lease

A named, expiring lock held as one row in the locks table. It replaces the four copies of the same code in KahunaCart and Forum Pro.

```php
use TrilbyMedia\GravDbKit\Database\Lease;

$lease = new Lease($db, $tables, $options, $clock);

if ($lease->acquire('catalog_index', 900)) {   // false: somebody else holds it
    try { … $lease->renew('catalog_index', 900); … } finally { $lease->release('catalog_index'); }
}

$lease->run('refund:order:7', fn () => $refunds->refund(…), 60);  // throws LeaseUnavailable when held
```

An expired row is taken over in place, so a process that died holding a lease blocks others for at most its TTL. Every acquisition gets its own owner string (the configured owner plus a random tail), and `release()` only deletes the row this object took, so two leases in one request never release each other's. No step depends on catching a failed insert, which keeps a lease usable inside an open PostgreSQL transaction and lets real errors (a deadlock) reach the caller instead of reading as "busy".

## KvStore, RateLimiter, UnsubscribeSigner, Clock

`KvStore($db, $tables, $clock)` holds installation-scoped values: `get`, `set`, `delete` and `remember($key, $create)`. `remember` writes insert-if-absent and reads back, so two first requests agree on one value (a secret that changed under a link already sent would break the link). Keys are 1 to 64 bytes; a longer one is refused rather than cut.

`RateLimiter($db, $tables, $pruneOdds = 50, $clock)` is KahunaCart's fixed-window limiter:

```php
$result = $limiter->hit('login', RateLimiter::anonymize($ip), limit: 10, windowSeconds: 900);
if (!$result->allowed) {
    header('Retry-After: ' . $result->retryAfter);
}
```

`hit(bucket, key, limit, window, ?now)` returns a `RateLimitResult` (`allowed`, `remaining`, `retryAfter`). Windows are aligned to the epoch; a denied call still counts; a limit of zero or less turns the bucket off and costs no queries. One row per bucket and key is rolled forward from window to window, and old rows are swept on roughly one call in `$pruneOdds` (0 turns that off). `prune($before)` drops windows that started before a time. Keys longer than 190 bytes are stored as their SHA-256. `anonymize($value)` hashes an IP or email for use as a key.

`UnsubscribeSigner($kv, $secretKey = 'unsubscribe_secret')` signs one-click unsubscribe links: `sign(string $subject, string $scope)` and `verify($subject, $scope, $token)`. The token is an HMAC-SHA256 over `{subject}|{scope}` with a secret created once in the KV store. A scope may not contain `|`.

`Clock` is one method, `now(): int`. `SystemClock` is `time()`. Every class that decides by time takes an optional `Clock`, and tests pass `Testing\FrozenClock`.

## Events

`EventSink::emit(string $event, array $payload): void` receives domain events after the write's transaction commits. Names are dotted (`ticket.created`), payloads flat, and a sink must never throw.

`CompositeSink` fans one emission out to several sinks. A sink that throws is caught and the others still run. `withErrorReporter(fn (Throwable $e, string $event, EventSink $sink) => $log->error(…))` returns a copy that reports those failures (a reporter that throws is ignored too). `add($sink)` appends one. `NullSink` discards everything.

## Testing helpers

- `EngineProvider($envPrefix = 'GRAVDBKIT_TEST', $tablePrefixes = ['kit_'], $options)` builds a fresh empty database for the engine the environment selects: `{PREFIX}_ENGINE` is `sqlite` (default), `mysql` or `pgsql`, and `{PREFIX}_MYSQL_DSN/_USER/_PASS`, `{PREFIX}_PGSQL_DSN/_USER/_PASS` reach the servers. On a server it drops every table under the given prefixes first. SQLite gets a new temporary file, removed at exit. `sibling()` opens a second connection to the same database for contention tests. Asking for a server engine without its DSN throws: a run meant to cover MySQL must not pass by covering nothing. `available($engine)` is there for tests that only want a server when one is configured.
- `MigratedDatabase($paths, $tables, $engines, $options, $envPrefix)` gives a migrated database per test. On SQLite it migrates once, snapshots the result as SQL and replays it into a new in-memory database for each call (milliseconds instead of a full migration run). On a server engine it drops and migrates every time. `{PREFIX}_MIGRATE_EACH=1` skips the snapshot when a migration itself is under test.
- `SpySink` records events (`of($event)`, `names()`, `reset()`), and `$explode = true` makes it throw.
- `FrozenClock` has `set()` and `advance()`.

### Running the kit's own tests

```
composer install
vendor/bin/phpunit                                   # SQLite

GRAVDBKIT_TEST_ENGINE=mysql \
GRAVDBKIT_TEST_MYSQL_DSN='mysql:unix_socket=/tmp/mysql.sock;dbname=gravdbkit_test;charset=utf8mb4' \
GRAVDBKIT_TEST_MYSQL_USER="$USER" vendor/bin/phpunit

GRAVDBKIT_TEST_ENGINE=pgsql \
GRAVDBKIT_TEST_PGSQL_DSN='pgsql:host=/tmp;dbname=gravdbkit_test' \
GRAVDBKIT_TEST_PGSQL_USER="$USER" vendor/bin/phpunit
```

A local MariaDB whose root account uses unix_socket auth is reachable through its socket as your own OS user. CI runs all three engines on PHP 8.3 and 8.4.

`tests/Integration/AdoptionTest.php` needs real Forum Pro and KahunaCart databases. It looks for them under `~/workspace/grav-forum` and `~/workspace/grav-kahunacart` (override with `GRAVDBKIT_ADOPT_FORUM_DB`, `GRAVDBKIT_ADOPT_FORUM_PLUGIN`, `GRAVDBKIT_ADOPT_KAHUNACART_DB`, `GRAVDBKIT_ADOPT_KAHUNACART_PLUGIN`) and skips, saying where it looked, when they are missing. It only ever copies the originals.

## Bundling with Strauss

Grav loads every plugin's autoloader into one process, so each plugin bundles its own copy of the kit under its own namespace prefix (`TrilbyMedia\GravDbKit\…` becomes, for example, `Grav\Plugin\HelpdeskPro\Vendor\TrilbyMedia\GravDbKit\…`). The kit is written so that works: no global state shared between copies, no `class_exists()` checks against its own unprefixed names, and no class names built from strings. Refer to kit classes with `use` statements and `::class`, which Strauss rewrites, including in migration files.

The exact Strauss config, commands, `.gitignore` rules and verification steps are in [docs/packaging.md](docs/packaging.md); `composer packaging-check` runs the harness that proves two prefixed copies coexist.

## Changes from the in-tree copies

Public class and method names follow KahunaCart's, with these differences:

- `Connection::__construct(PDO, Dialect, ?KitOptions)`: the savepoint prefix comes from `KitOptions` and defaults to `sp_` (KahunaCart hard-coded `cp_sp_`, Forum Pro `fp_sp_`). New `inTransaction()` and `options()`.
- `ConnectionFactory::create/sqlite/mysql/pgsql/fromPdo` take a trailing `?KitOptions`; `mysql()` also accepts `unix_socket`.
- `Migrator::__construct(Connection, string|array $paths, ?KitTables, ?KitOptions)`: table names come from `KitTables`, the lock TTL from `KitOptions` (the private `LOCK_TTL` constant is gone), and the run-lock is a `Lease`, whose owner string carries a random tail per acquisition. New `connection()` and `tables()`.
- `RateLimiter` moved from KahunaCart's `Security` namespace to `Support`. Its constructor is `(Connection, ?KitTables, int $pruneOdds = 50, ?Clock)`, so `pruneOdds:` as a named argument still works but not as the second positional one. The `TABLE` constant is gone (use `KitTables::$rateLimits`), and the table stores bucket and key in two columns (`bucket`, `rl_key`) instead of one joined `key`. Its insert is an insert-if-absent rather than a caught duplicate.
- Forum Pro's `RateLimiter::hit(string $key, int $window, int $max): bool` becomes `hit($bucket, $key, $limit, $window)->allowed`, and `prune()` becomes `prune(int $before)`.
- `KvStore` (Forum Pro's) takes `?KitTables` and `?Clock`, gains `delete()`, refuses keys over 64 bytes, and `remember()` no longer lets a second writer replace the first value.
- `UnsubscribeSigner::sign(string $subject, string $scope)` takes a string subject instead of Forum Pro's `int $userId`. The token for `(string)$userId` is byte-for-byte the one Forum Pro issued.
- `CompositeSink` gains `withErrorReporter()`, `add()` and `sinks()`.
- `SchemaGuard`, `Lease`, `KitTables`, `KitOptions`, `InfraTables` and the `Testing` classes are new. KahunaCart's `SettingsTable` and money conventions stay in KahunaCart.

## Switching an existing plugin

A plugin moves to the kit without moving any data: it passes the table names and savepoint prefix it already uses.

1. Require `getgrav/grav-db-kit` and set up the Strauss prefix.
2. Build the connection through the kit's `ConnectionFactory` with the plugin's `KitOptions`, and pass the plugin's `KitTables` to the `Migrator`, `Lease`, `KvStore`, `RateLimiter` and job queue.
3. In every migration file, point the three `use` lines at the kit (`…\Database\Connection`, `…\Database\Dialect\Dialect`, `…\Database\Migration`). Nothing else in a migration file changes, and no step re-runs: the tracking rows are keyed by migration and step name, which stay the same.
4. Point every other `use …\Database\Connection` (repositories, services, tests) at the kit, and delete the in-tree `classes/Database` (except anything plugin-specific, such as KahunaCart's `SettingsTable`).
5. Replace the in-tree lease code and `ensureMigrated()`/`autoMigrate()` with `Lease` and `SchemaGuard::ensure()`.
6. If the plugin uses the kit's job queue, append a step with `InfraTables::jobs($d, $tables)`, which brings an older jobs table forward in place. If it uses the kit's `RateLimiter`, replace its rate-limit table as described below.

`AdoptionTest` checks steps 2 and 3 against copies of real databases. For each plugin, the kit's migrator reports exactly the pending steps the plugin's own migrator reports, applies exactly those, and reports zero pending on a database the plugin itself brought current, without re-running anything.

### Forum Pro

- `KitTables(migrations: 'forum_migrations', locks: 'forum_locks', kv: 'forum_kv', jobs: 'forum_jobs', rateLimits: 'forum_rate_limits')` and `KitOptions(savepointPrefix: 'fp_sp_')`.
- 26 migration files change their three `use` lines. About 77 files in `classes/`, `forum-pro.php` and `tests/` import `Grav\Plugin\ForumPro\Database\…`.
- `ForumPro::ensureMigrated()` becomes `SchemaGuard::ensure($migrator, new KvSchemaState(new KvStore($db, $tables)), $policy)`. The same `migrations.complete` row is used; Forum Pro's stored value is a different hash, so the first request after the switch runs the full check once (and finds nothing to do).
- `forum_jobs` predates the dedupe and cancellation columns. A new step with `InfraTables::jobs()` adds them.
- `forum_rate_limits` has no `bucket` column and its unique key is `(rl_key, window_start)`, so it cannot be upgraded in place. Most rows are counters that expire within minutes, but `ViewCounter` also buffers unflushed topic view counts there under `views:topic:*` keys. The switching migration should rename the old table, create the new one with `InfraTables::rateLimits()`, copy the `views:%` rows across as bucket `views` (or flush them first), and drop the old table. `ViewCounter` then reads and writes `bucket = 'views'`, and every `hit('prefix:…', $window, $max)` call becomes `hit('prefix', '…', $max, $window)->allowed`. The kit's `prune()` has no exception for view rows, so `ViewCounter` must flush before rows age out, or keep its own prune.
- Unsubscribe links already sent keep verifying: pass the user id as a string.

### KahunaCart

- `KitTables::withPrefix('kahunacart')` and `KitOptions(savepointPrefix: 'cp_sp_')`. KahunaCart has no KV table, so it keeps its Grav-cache schema state through `CallbackSchemaState`, and needs `InfraTables::kv()` only if it adopts `KvStore`.
- 83 migration files change their three `use` lines. Migration 0061 also calls `Grav\Plugin\KahunaCart\Database\SettingsTable::upsert()`, which stays in KahunaCart and must type-hint the kit's `Connection`. About 149 files in `classes/` and `kahunacart.php` import `Grav\Plugin\KahunaCart\Database\…`.
- Add-ons are the largest part of the switch. Their migration files implement KahunaCart's `Migration` interface and their code type-hints KahunaCart's `Connection`: bookings (24 files), dummy (4), licenses (20), newsletters (65), rentals (10), shipping (14), subscriptions (24). Once KahunaCart bundles the kit under its Strauss prefix, those names change, so the add-ons must be released in step with KahunaCart. KahunaCart 1.3 decouples them with `class_alias()` shims for the old `Database\*`, `Jobs\*` and `Security\RateLimitResult` names pointing at the bundled kit classes, registered all at once (see "Switching Forum Pro and KahunaCart later" in docs/packaging.md for why not lazily), plus thin wrappers for `Migrator` and `RateLimiter`, whose old constructors add-on tests still call. Add-ons run unchanged.
- The catalog index drain (`IndexBuilder`) and the refund lock (`TransactionRepository::withOrderLock`) become `Lease` calls. The refund lock keeps throwing its own `RefundLocked` on `acquire() === false`.
- `kahunacart_rate_limits` keeps bucket and key joined in one `key` primary key. Its rows are pure counters, so the switching migration can drop it and recreate it with `InfraTables::rateLimits()`. Callers change from `Security\RateLimiter` to `Support\RateLimiter` with the same `hit()` arguments.
- `kahunacart_jobs` already has every column `InfraTables::jobs()` creates (plus `store_id`, which the kit leaves alone).

## License

MIT, Copyright (c) 2026 Trilby Media, LLC. See [LICENSE](LICENSE).
