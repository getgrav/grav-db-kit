<?php

declare(strict_types=1);

/**
 * YetiSearch in one process twice: plugin-k bundles 2.3.6 from Packagist and
 * kit 1.0.0, both unprefixed (KahunaCart today), and loads first; plugin-y
 * bundles YetiSearch 2.4 and kit 1.0.1 under PluginY\Vendor\ (Helpdesk Pro's
 * plan, with the documented config). Each indexes and searches its own SQLite
 * file.
 *
 * Usage: php yetisearch.php <site dir> <data dir> <plugin-y version label>
 */

require __DIR__ . '/_grav.php';

[$site, $data, $yLabel] = [$argv[1], $argv[2], $argv[3]];

// Grav core's autoloader comes first and supplies psr/log.
require "{$site}/host/vendor/autoload.php";
loadPluginLikeGrav("{$site}/plugin-k", 'plugin-k', 'PluginK\\PluginK');
loadPluginLikeGrav("{$site}/plugin-y", 'plugin-y', 'PluginY\\PluginY');

$k = attempt('plugin-k (unprefixed 2.3.6) indexed and searched', static fn () => PluginK\SearchProbe::run("{$data}/search-k.db"));
$y = attempt("plugin-y (prefixed {$yLabel}) indexed and searched", static fn () => PluginY\SearchProbe::run("{$data}/search-y.db"));

if ($k !== null) {
    expect('plugin-k finds its document', $k['hits'] === ['doc-1'], $k['hits']);
    expect('plugin-k carries its own unprefixed kit (1.0.0)', $k['kit'] === '1.0.0', $k['kit_file']);
    expect('plugin-k uses its own unprefixed copy', $k['yeti_class'] === 'YetiSearch\\YetiSearch'
        && str_contains($k['yeti_file'], '/plugin-k/vendor/yetidevworks/'), $k['yeti_file']);
}
if ($y !== null) {
    expect('plugin-y finds its document through the prefixed classes', $y['hits'] === ['doc-1'], $y['hits']);
    expect('plugin-y uses its own prefixed copy', $y['yeti_class'] === 'PluginY\\Vendor\\YetiSearch\\YetiSearch'
        && str_contains($y['yeti_file'], '/plugin-y/vendor-prefixed/yetidevworks/'), $y['yeti_file']);
    expect('the prefixed copy accepted an unprefixed Psr\\Log logger', true);
    expect('plugin-y carries its own prefixed kit (1.0.1) beside YetiSearch', $y['kit'] === '1.0.1'
        && str_contains($y['kit_file'], '/plugin-y/vendor-prefixed/getgrav/'), $y['kit_file']);
}
if ($k !== null && $y !== null && $y['has_semantic_seam']) {
    expect('the 2.4 semantic seam exists only in the prefixed copy', !$k['has_semantic_seam'], [$k['has_semantic_seam'], $y['has_semantic_seam']]);
}

report(['plugin-k' => $k, 'plugin-y' => $y]);
