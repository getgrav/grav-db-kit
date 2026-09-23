<?php

declare(strict_types=1);

/**
 * What Grav's Plugins::init() does with each enabled plugin, minus Grav:
 * require the main file, build the plugin, call autoload(), then move the
 * returned Composer loader behind the loaders already registered (Grav moves
 * plugin loaders behind core's, keeping the plugins' relative order). The
 * first plugin loaded therefore answers first for any class two plugins both
 * map. Grav 2.x also puts a PluginAutoloader index in front; it asks the same
 * loaders in the same order, so the winner is the same.
 */

use Composer\Autoload\ClassLoader;

/** @var list<array{name: string, ok: bool, detail: string}> $GLOBALS['checks'] */
$GLOBALS['checks'] = [];

function loadPluginLikeGrav(string $dir, string $slug, string $class): ClassLoader
{
    require_once "{$dir}/{$slug}.php";
    $plugin = new $class();
    $loader = $plugin->autoload();
    if (!$loader instanceof ClassLoader) {
        throw new RuntimeException("{$slug}: autoload() did not return a ClassLoader");
    }
    $loader->unregister();
    $loader->register(false);

    return $loader;
}

function expect(string $name, bool $ok, mixed $detail = ''): void
{
    $GLOBALS['checks'][] = [
        'name' => $name,
        'ok' => $ok,
        'detail' => is_string($detail) ? $detail : json_encode($detail, JSON_UNESCAPED_SLASHES),
    ];
}

/** @param array<string, mixed> $facts */
function report(array $facts): never
{
    echo json_encode(['checks' => $GLOBALS['checks'], 'facts' => $facts], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), "\n";
    exit(0);
}

/** Run $fn and turn anything it throws into a failed check instead of a crash. */
function attempt(string $name, callable $fn): mixed
{
    try {
        return $fn();
    } catch (Throwable $e) {
        expect($name, false, $e::class . ': ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());

        return null;
    }
}

/** @return list<string> */
function sqliteTables(string $dbPath): array
{
    $pdo = new PDO('sqlite:' . $dbPath);

    return $pdo->query("SELECT name FROM sqlite_master WHERE type = 'table' ORDER BY name")->fetchAll(PDO::FETCH_COLUMN);
}
