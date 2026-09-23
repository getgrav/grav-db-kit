<?php

declare(strict_types=1);

/**
 * The failure mode prefixing prevents: two plugins bundling grav-db-kit
 * unprefixed (plugin-c 1.0.0, plugin-d 1.0.1). Both map TrilbyMedia\GravDbKit\
 * and whichever plugin Grav loads first supplies the classes to both, with no
 * error or warning.
 *
 * Usage: php unprefixed.php <site dir> <data dir> <first> <second>
 *   e.g. plugin-c plugin-d, or plugin-d plugin-c to flip the load order.
 */

require __DIR__ . '/_grav.php';

[$site, $data, $first, $second] = [$argv[1], $argv[2], $argv[3], $argv[4]];
$db = "{$data}/unprefixed-{$first}.sqlite";
@unlink($db);

$classOf = static fn (string $slug): string => 'Plugin' . strtoupper(substr($slug, -1));

foreach ([$first, $second] as $slug) {
    loadPluginLikeGrav("{$site}/{$slug}", $slug, $classOf($slug) . '\\' . $classOf($slug));
}

$results = [];
foreach ([$first, $second] as $slug) {
    $probe = $classOf($slug) . '\\Probe';
    $results[$slug] = attempt("{$slug} probe ran", static fn () => $probe::run($db));
}

$winner = $results[$first];
$loser = $results[$second];
if ($winner !== null && $loser !== null) {
    expect("{$first} (loaded first) gets its own kit {$winner['expects']}", $winner['marker'] === $winner['expects'], $winner['marker']);
    expect("{$second} expected {$loser['expects']} but silently got {$first}'s {$winner['marker']}", $loser['marker'] === $winner['marker']
        && $loser['marker'] !== $loser['expects'], $loser['marker']);
    expect("{$second}'s kit classes load from {$first}/vendor/", str_contains((string) $loser['marker_file'], "/{$first}/vendor/"), $loser['marker_file']);
    expect("{$second}'s migrations and job still ran (on the wrong copy)", $loser['up_to_date'] && $loser['job_completed'], $loser['run']);
    $expectsNewer = $loser['expects'] === '1.0.1';
    expect(
        $expectsNewer
            ? "{$second} calling a 1.0.1 method fails at runtime"
            : "{$second} runs on 1.0.1 code it was never tested against",
        $expectsNewer
            ? str_starts_with((string) $loser['added_in_101'], 'Error: Call to undefined method')
            : $loser['added_in_101'] === 'added in 1.0.1',
        $loser['added_in_101'],
    );
}

report(['order' => [$first, $second], 'results' => $results]);
