<?php

declare(strict_types=1);

/**
 * grav-db-kit packaging check.
 *
 * Builds throwaway fake Grav plugins that bundle the kit (and YetiSearch)
 * through Strauss, loads them into one PHP process the way Grav does, and
 * proves the prefixed copies coexist while unprefixed copies collide. See
 * docs/packaging.md.
 *
 *   php tools/packaging-check/run.php                 everything
 *   php tools/packaging-check/run.php --no-yetisearch the kit only (no network
 *                                                     once strauss.phar is cached)
 *   php tools/packaging-check/run.php --keep          leave build/ for inspection
 *
 * Needs php (pdo_sqlite, sqlite3, mbstring), composer, git and curl on PATH.
 * The YetiSearch part fetches YetiSearch 2.3.6 and 2.4.0 and psr/log from
 * Packagist; YETISEARCH_PATH=/path prefixes a local YetiSearch checkout
 * instead of 2.4.0.
 *
 * Every plugin is built in build/<slug>/ with `composer install --no-dev` (a
 * release build), then "shipped" into build/site/<slug>/: a prefixed plugin
 * ships exactly what git would commit under the documented .gitignore. The
 * runtime checks load the shipped copies, so they prove the committed files
 * are enough on a site with no composer step.
 */

namespace PackagingCheck;

require __DIR__ . '/lib/build.php';

$args = array_slice($argv, 1);
$withYeti = !in_array('--no-yetisearch', $args, true);
$keep = in_array('--keep', $args, true);

$here = __DIR__;
$kitRoot = \dirname($here, 2);
$build = "{$here}/build";
// YETISEARCH_PATH=/path/to/yetisearch prefixes a local checkout instead of
// YetiSearch 2.4.0 from Packagist (to try an unreleased change).
$yetiLocal = getenv('YETISEARCH_PATH') ?: null;

$started = microtime(true);
$step = static function (string $msg): void {
    fwrite(STDOUT, "  · {$msg}\n");
};

echo "grav-db-kit packaging check (Strauss " . STRAUSS_VERSION . ")\n\n";

rmTree($build);
mkdir("{$build}/data", 0777, true);
$phar = strauss("{$here}/.cache");
$step('strauss.phar ' . STRAUSS_VERSION . ' verified');

// Two snapshots of the kit: identical code plus a marker class, and a method
// that only 1.0.1 has.
foreach (['1.0.0', '1.0.1'] as $version) {
    $dir = "{$build}/kit-{$version}";
    snapshotKit($kitRoot, $dir);
    $extra = $version === '1.0.1'
        ? "\n    public static function addedIn101(): string\n    {\n        return 'added in 1.0.1';\n    }\n"
        : '';
    writeFile("{$dir}/src/PackagingMarker.php", <<<PHP
        <?php

        declare(strict_types=1);

        namespace TrilbyMedia\\GravDbKit;

        /** Added by tools/packaging-check to tell the snapshots apart. */
        final class PackagingMarker
        {
            public const VERSION = '{$version}';
        {$extra}}

        PHP);
}
$step('kit snapshots 1.0.0 and 1.0.1 written');

// A dev-only package every plugin requires in require-dev, standing in for
// phpunit: it must not reach vendor-prefixed/ or the committed autoloader.
writeFile("{$build}/dev-tool/composer.json", encodeJson([
    'name' => 'packaging-check/dev-tool',
    'type' => 'library',
    'autoload' => ['psr-4' => ['DevTool\\' => 'src/']],
]));
writeFile("{$build}/dev-tool/src/Tool.php", "<?php\nnamespace DevTool;\nfinal class Tool {}\n");
$devRepo = pathRepo('../dev-tool', 'packaging-check/dev-tool', '1.0.0');
$devReq = ['packaging-check/dev-tool' => '1.0.0'];

$site = "{$build}/site";
$shipped = [];

/**
 * @param array<string, mixed> $composer
 * @param array<string, string> $vars
 */
$makePlugin = static function (string $slug, string $template, array $composer, array $vars, bool $prefixed) use ($build, $site, $here, $phar, $step, &$shipped): void {
    $dir = "{$build}/{$slug}";
    renderTemplates("{$here}/templates/{$template}", $dir, $vars + ['SLUG' => $slug]);
    writeFile("{$dir}/composer.json", encodeJson($composer));
    if ($prefixed) {
        // What strauss:install would download; copied so the kit part of the
        // check needs no network once the cache is warm.
        mkdir("{$dir}/bin", 0777, true);
        copy($phar, "{$dir}/bin/strauss.phar");
    }
    // --no-dev is how a release is built: the committed vendor/composer/ must
    // not map dev packages.
    sh(['composer', 'install', '--no-dev', '--no-interaction', '--no-progress'], $dir);
    $shipped[$slug] = ship($dir, "{$site}/{$slug}", $prefixed);
    $step("{$slug} built" . ($prefixed ? ', prefixed' : ' (unprefixed)') . ' and shipped (' . \count($shipped[$slug]) . ' files)');
};

$noPackagist = ['packagist.org' => false];
$kitPlugins = [
    // slug => [kit version, prefix or null]
    'plugin-a' => ['1.0.0', 'PluginA\\Vendor\\'],
    'plugin-b' => ['1.0.1', 'PluginB\\Vendor\\'],
    'plugin-c' => ['1.0.0', null],
    'plugin-d' => ['1.0.1', null],
];
foreach ($kitPlugins as $slug => [$version, $prefix]) {
    $ns = 'Plugin' . strtoupper(substr($slug, -1));
    $strauss = $prefix === null ? null : straussConfig($prefix, "{$ns}_Vendor_", ['getgrav/grav-db-kit']);
    $composer = pluginComposer(
        $slug,
        $ns,
        [pathRepo("../kit-{$version}", 'getgrav/grav-db-kit', $version), $devRepo, $noPackagist],
        ['getgrav/grav-db-kit' => $version],
        $devReq,
        $strauss,
    );
    $makePlugin($slug, 'kit-plugin', $composer, [
        'NS' => $ns,
        'CLASS' => $ns,
        'KIT' => ($prefix ?? '') . 'TrilbyMedia\\GravDbKit',
        'TABLE' => strtolower($ns),
        'EXPECTS' => $version,
    ], $prefix !== null);
}

$yLabel = null;
if ($withYeti) {
    // Grav core's own vendor/, reduced to the one package that matters here:
    // psr/log, which Grav ships and the prefixed YetiSearch keeps sharing.
    writeFile("{$site}/host/composer.json", encodeJson([
        'name' => 'packaging-check/host',
        'require' => ['psr/log' => '3.0.2'],
        'config' => ['platform-check' => false],
    ]));
    sh(['composer', 'install', '--no-interaction', '--no-progress'], "{$site}/host");
    $step('host (psr/log 3.0.2, as in Grav core) installed');

    // plugin-k: YetiSearch 2.3.6 from Packagist, unprefixed, like KahunaCart.
    $makePlugin('plugin-k', 'search-plugin', pluginComposer(
        'plugin-k',
        'PluginK',
        [pathRepo('../kit-1.0.0', 'getgrav/grav-db-kit', '1.0.0')],
        ['getgrav/grav-db-kit' => '1.0.0', 'yetidevworks/yetisearch' => '2.3.6'],
        [],
        null,
    ), ['NS' => 'PluginK', 'CLASS' => 'PluginK', 'KIT' => 'TrilbyMedia\\GravDbKit', 'YETI' => 'YetiSearch', 'WORD' => 'kahunaword'], false);

    // plugin-y: YetiSearch 2.4 prefixed, like Helpdesk Pro. psr/log stays
    // unprefixed and unshipped: Grav core provides it, and a prefixed copy
    // would refuse Grav's own logger.
    if ($yetiLocal !== null) {
        $yLabel = 'local ' . trim(sh(['git', 'describe', '--tags', '--always', '--dirty'], $yetiLocal, true));
        $repos = [pathRepo($yetiLocal, 'yetidevworks/yetisearch', '2.4.99')];
        $req = ['yetidevworks/yetisearch' => '2.4.99'];
    } else {
        $yLabel = '2.4.0';
        $repos = [];
        $req = ['yetidevworks/yetisearch' => '2.4.0'];
    }
    // The kit and YetiSearch together under one prefix: the exact config
    // docs/packaging.md tells a plugin to copy.
    $yComposer = pluginComposer(
        'plugin-y',
        'PluginY',
        [pathRepo('../kit-1.0.1', 'getgrav/grav-db-kit', '1.0.1'), ...$repos, $devRepo],
        ['getgrav/grav-db-kit' => '1.0.1'] + $req,
        $devReq,
        straussConfig('PluginY\\Vendor\\', 'PluginY_Vendor_', ['getgrav/grav-db-kit', 'yetidevworks/yetisearch'], ['psr/log']),
    );
    $makePlugin('plugin-y', 'search-plugin', $yComposer, [
        'NS' => 'PluginY',
        'CLASS' => 'PluginY',
        'KIT' => 'PluginY\\Vendor\\TrilbyMedia\\GravDbKit',
        'YETI' => 'PluginY\\Vendor\\YetiSearch',
        'WORD' => 'helpdeskword',
    ], true);
}

// A developer's everyday `composer install` (with dev packages, for phpunit)
// re-runs Strauss. It must leave vendor-prefixed/ as the release build made
// it, apart from the dev flag in installed.php, and keep the dev package out.
sh(['composer', 'install', '--no-interaction', '--no-progress'], "{$build}/plugin-a");
$devDrift = [];
$devTree = static function (string $root): array {
    $out = [];
    $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS));
    foreach ($it as $f) {
        $out[substr($f->getPathname(), \strlen($root) + 1)] = hash_file('sha256', $f->getPathname());
    }
    ksort($out);

    return $out;
};
$released = $devTree("{$site}/plugin-a/vendor-prefixed");
$dev = $devTree("{$build}/plugin-a/vendor-prefixed");
foreach (array_unique([...array_keys($released), ...array_keys($dev)]) as $rel) {
    if (($released[$rel] ?? null) !== ($dev[$rel] ?? null) && $rel !== 'composer/installed.php') {
        $devDrift[] = "vendor-prefixed/{$rel} differs after a dev install";
    }
}
if (!str_contains((string) file_get_contents("{$build}/plugin-a/vendor/composer/autoload_psr4.php"), 'DevTool')) {
    $devDrift[] = 'the dev install did not make the dev package loadable';
}
$step('plugin-a reinstalled with dev packages');

// Static scan: any unprefixed reference left in a prefixed copy is a class
// name Strauss did not recognise, and it would resolve to somebody else's
// copy at runtime. First prove the scan catches one.
$planted = "{$build}/scan-selftest";
writeFile("{$planted}/Probe.php", <<<'PHP'
    <?php
    // TrilbyMedia\GravDbKit\Jobs\JobQueue in a comment is fine.
    use PluginA\Vendor\TrilbyMedia\GravDbKit\Jobs\JobQueue;
    class_exists('TrilbyMedia\\GravDbKit\\Jobs\\JobQueue');
    $x = \TrilbyMedia\GravDbKit\Jobs\JobRunner::class;
    PHP);
$scanWorks = count(unprefixedReferences($planted, 'TrilbyMedia\\GravDbKit')) === 2;
$leftovers = unprefixedReferences("{$site}/plugin-a/vendor-prefixed", 'TrilbyMedia\\GravDbKit');
if ($withYeti) {
    $leftovers = [
        ...$leftovers,
        ...unprefixedReferences("{$site}/plugin-y/vendor-prefixed", 'YetiSearch'),
        ...unprefixedReferences("{$site}/plugin-y/vendor-prefixed", 'TrilbyMedia\\GravDbKit'),
    ];
}

// Take the prefix out of each shipped kit copy again: what is left must be
// the kit source byte for byte, so Strauss renamed and did nothing else.
$rewrite = [
    ...diffAfterUnprefixing("{$site}/plugin-a/vendor-prefixed/getgrav/grav-db-kit/src", "{$build}/kit-1.0.0/src", 'PluginA\\Vendor\\'),
    ...diffAfterUnprefixing("{$site}/plugin-b/vendor-prefixed/getgrav/grav-db-kit/src", "{$build}/kit-1.0.1/src", 'PluginB\\Vendor\\'),
];
if ($withYeti) {
    $rewrite = [...$rewrite, ...diffAfterUnprefixing("{$site}/plugin-y/vendor-prefixed/getgrav/grav-db-kit/src", "{$build}/kit-1.0.1/src", 'PluginY\\Vendor\\')];
}

// What a prefixed plugin ships besides its own code: vendor-prefixed/,
// vendor/autoload.php and vendor/composer/. The autoloader must not map the
// unprefixed package (Strauss removed it from vendor/), the dev package, or
// Strauss's development alias file.
$shipProblems = [];
foreach ($withYeti ? ['plugin-a', 'plugin-b', 'plugin-y'] : ['plugin-a', 'plugin-b'] as $slug) {
    $files = $shipped[$slug];
    foreach (['vendor/autoload.php', 'vendor/composer/autoload_real.php', 'vendor-prefixed/autoload.php', 'vendor-prefixed/composer/autoload_static.php'] as $must) {
        if (!in_array($must, $files, true)) {
            $shipProblems[] = "{$slug} does not ship {$must}";
        }
    }
    foreach ($files as $f) {
        if (preg_match('#^(bin/|vendor/(?!autoload\.php$|composer/)|vendor/composer/autoload_aliases\.php$|vendor-prefixed/packaging-check/)#', $f)) {
            $shipProblems[] = "{$slug} ships {$f}";
        }
    }
    foreach (glob("{$site}/{$slug}/vendor/composer/autoload_*.php") as $f) {
        $body = (string) file_get_contents($f);
        foreach (["'TrilbyMedia\\\\GravDbKit\\\\'", "'YetiSearch\\\\'", 'DevTool', 'autoload_aliases'] as $needle) {
            if (str_contains($body, $needle)) {
                $shipProblems[] = substr($f, \strlen($site) + 1) . " mentions {$needle}";
            }
        }
    }
}

// docs/packaging.md shows the composer.json a plugin copies. Its Strauss
// block and scripts must be the ones this check just ran (plugin-y), with
// only the prefix names changed.
$docDrift = [];
if ($withYeti) {
    $doc = (string) @file_get_contents("{$kitRoot}/docs/packaging.md");
    $snippet = preg_match('/```json\n(.*?)\n```/s', $doc, $m) ? json_decode($m[1], true) : null;
    if (!\is_array($snippet)) {
        $docDrift[] = 'no composer.json snippet found in docs/packaging.md';
    } else {
        $documented = $snippet['extra']['strauss'] ?? [];
        $documented['namespace_prefix'] = $yComposer['extra']['strauss']['namespace_prefix'];
        $documented['classmap_prefix'] = $yComposer['extra']['strauss']['classmap_prefix'];
        if ($documented != $yComposer['extra']['strauss']) {
            $docDrift[] = 'extra.strauss differs: ' . json_encode($documented, JSON_UNESCAPED_SLASHES) . ' vs ' . json_encode($yComposer['extra']['strauss'], JSON_UNESCAPED_SLASHES);
        }
        foreach ($yComposer['scripts'] as $name => $body) {
            if (($snippet['scripts'][$name] ?? null) != $body) {
                $docDrift[] = "script {$name} differs";
            }
        }
        if (($snippet['autoload']['files'] ?? null) != $yComposer['autoload']['files']) {
            $docDrift[] = 'autoload.files differs';
        }
    }
    if (!str_contains($doc, PLUGIN_GITIGNORE)) {
        $docDrift[] = 'the .gitignore block differs';
    }
}

// Run each scenario in a fresh PHP process, as Grav would.
$scenarios = [
    'prefixed copies coexist (plugin-a 1.0.0 + plugin-b 1.0.1)' => ['prefixed.php', $site, "{$build}/data"],
    'unprefixed copies collide (plugin-c 1.0.0 loaded first)' => ['unprefixed.php', $site, "{$build}/data", 'plugin-c', 'plugin-d'],
    'unprefixed copies collide (plugin-d 1.0.1 loaded first)' => ['unprefixed.php', $site, "{$build}/data", 'plugin-d', 'plugin-c'],
];
if ($withYeti) {
    $scenarios["YetiSearch prefixed ({$yLabel}) beside unprefixed 2.3.6"] = ['yetisearch.php', $site, "{$build}/data", $yLabel];
}

$failed = 0;
$total = 0;
foreach ($scenarios as $title => $scenario) {
    echo "\n{$title}\n";
    $script = array_shift($scenario);
    $out = sh([PHP_BINARY, "{$here}/checks/{$script}", ...$scenario], $here, true);
    $data = json_decode($out, true);
    if (!\is_array($data)) {
        echo "  FAIL  the check script crashed:\n" . preg_replace('/^/m', '        ', $out) . "\n";
        $failed++;
        $total++;
        continue;
    }
    foreach ($data['checks'] as $c) {
        $total++;
        $failed += $c['ok'] ? 0 : 1;
        echo '  ' . ($c['ok'] ? 'ok  ' : 'FAIL') . "  {$c['name']}" . ($c['ok'] ? '' : "\n        {$c['detail']}") . "\n";
    }
}

$static = [
    'the leftover scan catches a planted string class name' => [$scanWorks, []],
    'no unprefixed references left in the prefixed copies' => [$leftovers === [], $leftovers],
    'each prefixed kit copy is the kit source with only the names changed' => [$rewrite === [], $rewrite],
    'docs/packaging.md shows the config this check ran' => [$docDrift === [], $docDrift],
    'a dev install changes nothing in vendor-prefixed/ but the dev flag' => [$devDrift === [], $devDrift],
    'prefixed plugins ship vendor-prefixed/ and the autoloader, nothing else from vendor/, no dev package' => [$shipProblems === [], $shipProblems],
];
echo "\nstatic checks on the build output\n";
foreach ($static as $name => [$ok, $detail]) {
    $total++;
    $failed += $ok ? 0 : 1;
    echo '  ' . ($ok ? 'ok  ' : 'FAIL') . "  {$name}\n";
    foreach ($detail as $line) {
        echo "        {$line}\n";
    }
}

if (!$keep) {
    rmTree($build);
}

printf("\n%s: %d/%d checks passed in %.1fs%s\n", $failed === 0 ? 'PASS' : 'FAIL', $total - $failed, $total, microtime(true) - $started, $keep ? " (build kept in {$build})" : '');
exit($failed === 0 ? 0 : 1);
