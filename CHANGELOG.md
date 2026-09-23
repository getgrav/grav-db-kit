# v1.0.1
## 09/23/2026

1. [](#improved)
    * Composer package renamed to `getgrav/grav-db-kit` to match its GitHub home. The PHP namespace stays `TrilbyMedia\GravDbKit\`

# v1.0.0
## 09/23/2026

1. [](#new)
    * First release: the database layer shared by Trilby Media's Grav plugins, extracted from KahunaCart and Forum Pro
    * `Database\Connection` thin PDO wrapper with savepoint-based nested transactions that retry on busy and deadlock errors, `insertMany`, `syncIdentitySequence`, `upsert` and `isUniqueViolation`
    * SQLite, MySQL/MariaDB and PostgreSQL dialects (upsert, insert-returning-id, table/column/index probes, retryable and duplicate-key error classification)
    * `ConnectionFactory` with the SQLite contract (WAL, foreign keys, busy timeout, `synchronous=NORMAL`, deny-all `.htaccess` and `index.html` in the database directory)
    * Upgrade-only `Migrator`: per-step tracking, several migration directories in run order, migration-set fingerprint, one `require` per migration file per process
    * `KitTables` and `KitOptions` so every table name, the savepoint prefix, the lock owner and the lock TTL are the plugin's own
    * `Lease`, one implementation of the expiring database lock the migrator, queue drains and per-record locks share
    * `SchemaGuard::ensure()` with `auto`, `sqlite`, `sqlite-only` and `manual` policies, and `KvSchemaState` / `CallbackSchemaState` stores
    * `Schema\InfraTables` migration steps for the KV, jobs and rate-limit tables
    * `Support\KvStore`, `Support\RateLimiter` (fixed window, buckets, `RateLimitResult`), `Support\UnsubscribeSigner`, `Support\Clock` / `SystemClock`
    * `Events\EventSink`, `CompositeSink` (sink failures caught and reported, never thrown) and `NullSink`
    * `docs/packaging.md` and `tools/packaging-check`: the Strauss recipe each plugin uses to bundle a namespace-prefixed copy, with a harness proving two prefixed copies coexist in one PHP process
    * `Testing\EngineProvider`, `MigratedDatabase` (snapshot replay into in-memory SQLite), `SpySink` and `FrozenClock` for plugin test suites
    * CI on SQLite, MySQL and PostgreSQL
