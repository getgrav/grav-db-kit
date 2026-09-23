<?php

declare(strict_types=1);

/**
 * Two plugins, each with its own Strauss-prefixed copy of grav-db-kit (1.0.0
 * under PluginA\Vendor\, 1.0.1 under PluginB\Vendor\), loaded into one PHP
 * process the way Grav loads them, both writing to one SQLite file under their
 * own table prefixes.
 *
 * Usage: php prefixed.php <site dir> <data dir>
 */

require __DIR__ . '/_grav.php';

[$site, $data] = [$argv[1], $argv[2]];
$db = "{$data}/prefixed.sqlite";
@unlink($db);

loadPluginLikeGrav("{$site}/plugin-a", 'plugin-a', 'PluginA\\PluginA');
loadPluginLikeGrav("{$site}/plugin-b", 'plugin-b', 'PluginB\\PluginB');

expect('no unprefixed kit class is loadable', !class_exists('TrilbyMedia\\GravDbKit\\PackagingMarker'));

$a = attempt('plugin-a probe ran', static fn () => PluginA\Probe::run($db));
$b = attempt('plugin-b probe ran', static fn () => PluginB\Probe::run($db));

foreach (['a' => $a, 'b' => $b] as $key => $r) {
    if ($r === null) {
        continue;
    }
    $slug = "plugin-{$key}";
    expect("{$slug} sees its own kit version ({$r['expects']})", $r['marker'] === $r['expects'], $r['marker']);
    expect("{$slug} kit classes load from its own vendor-prefixed/", str_contains((string) $r['marker_file'], "/{$slug}/vendor-prefixed/")
        && str_contains((string) $r['queue_file'], "/{$slug}/vendor-prefixed/"), [$r['marker_file'], $r['queue_file']]);
    expect("{$slug} migrations applied through its own Migrator", count($r['applied']) === 3 && $r['up_to_date'], $r['applied']);
    expect("{$slug} tracking table lists only its own steps", $r['migrations_rows'] === $r['applied'], $r['migrations_rows']);
    expect("{$slug} job ran through its own JobQueue/JobRunner", $r['job_completed'] && $r['run']['processed'] === 1
        && $r['notes'] === ["hello from {$slug}"], ['run' => $r['run'], 'state' => $r['job_state'], 'notes' => $r['notes']]);
}

if ($a !== null && $b !== null) {
    expect('1.0.1-only method is missing from the 1.0.0 copy', str_starts_with((string) $a['added_in_101'], 'Error: Call to undefined method'), $a['added_in_101']);
    expect('1.0.1-only method answers in the 1.0.1 copy', $b['added_in_101'] === 'added in 1.0.1', $b['added_in_101']);
    expect('the two copies are different classes', $a['marker_class'] !== $b['marker_class'], [$a['marker_class'], $b['marker_class']]);
}

$tables = sqliteTables($db);
$want = [];
foreach (['plugina', 'pluginb'] as $p) {
    foreach (['jobs', 'kv', 'locks', 'migrations', 'notes'] as $t) {
        $want[] = "{$p}_{$t}";
    }
}
expect('both plugins own separate table sets in one database', array_values(array_intersect($want, $tables)) === $want, $tables);

report(['plugin-a' => $a, 'plugin-b' => $b, 'tables' => $tables]);
