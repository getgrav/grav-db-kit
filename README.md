# grav-db-kit

Shared database, migration, job queue and event infrastructure for Grav plugins by Trilby Media: a PDO wrapper with SQLite, MySQL and PostgreSQL dialects, an upgrade-only migrator, a database-backed job queue with an inline drain, an event sink backbone, a rate limiter and a key/value store.

It is a Composer package, not a Grav plugin. Each plugin bundles its own copy (namespace-prefixed with Strauss), so plugins update it in their own releases and never need another plugin installed.

Work in progress.
