<?php

declare(strict_types=1);

/**
 * Build helpers for the packaging check: shell calls, file copies, template
 * rendering and the Strauss download. No Grav, no Composer classes: this runs
 * before any of the fake plugins has a vendor/ directory.
 */

namespace PackagingCheck;

const STRAUSS_VERSION = '0.30.0';
const STRAUSS_SHA256 = '08c1a8e553594745c22294e158129005fd11ed09ed452d7d4f48566f38c66c96';

/**
 * Run a command, echo nothing unless it fails, and return its output.
 *
 * @param list<string> $argv
 */
function sh(array $argv, ?string $cwd = null, bool $mayFail = false): string
{
    $cmd = implode(' ', array_map('escapeshellarg', $argv));
    $proc = proc_open($cmd . ' 2>&1', [1 => ['pipe', 'w']], $pipes, $cwd);
    if (!\is_resource($proc)) {
        throw new \RuntimeException("could not start: {$cmd}");
    }
    $out = stream_get_contents($pipes[1]);
    fclose($pipes[1]);
    $code = proc_close($proc);
    if ($code !== 0 && !$mayFail) {
        throw new \RuntimeException("command failed ({$code}) in {$cwd}: {$cmd}\n{$out}");
    }

    return (string) $out;
}

function rmTree(string $path): void
{
    if (!file_exists($path) && !is_link($path)) {
        return;
    }
    if (is_link($path) || is_file($path)) {
        unlink($path);

        return;
    }
    foreach (new \FilesystemIterator($path) as $item) {
        rmTree($item->getPathname());
    }
    rmdir($path);
}

function writeFile(string $path, string $contents): void
{
    if (!is_dir(\dirname($path))) {
        mkdir(\dirname($path), 0777, true);
    }
    file_put_contents($path, $contents);
}

/**
 * Copy the kit's working tree (tracked plus untracked-but-not-ignored files)
 * into $target, leaving out tools/. The working tree rather than HEAD, so the
 * check covers uncommitted src/ changes too.
 */
function snapshotKit(string $kitRoot, string $target): void
{
    $list = sh(['git', 'ls-files', '-co', '--exclude-standard'], $kitRoot);
    foreach (preg_split('/\R/', trim($list)) as $rel) {
        if ($rel === '' || str_starts_with($rel, 'tools/') || !is_file("{$kitRoot}/{$rel}")) {
            continue;
        }
        writeFile("{$target}/{$rel}", (string) file_get_contents("{$kitRoot}/{$rel}"));
    }
}

/**
 * Render every *.tpl file under $templateDir into $target (dropping the .tpl),
 * replacing {{KEY}} placeholders.
 *
 * @param array<string, string> $vars
 */
function renderTemplates(string $templateDir, string $target, array $vars): void
{
    $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($templateDir, \FilesystemIterator::SKIP_DOTS));
    foreach ($it as $file) {
        $rel = substr($file->getPathname(), \strlen($templateDir) + 1);
        $rel = preg_replace('/\.tpl$/', '', $rel);
        $rel = strtr($rel, array_combine(
            array_map(static fn ($k) => "{{{$k}}}", array_keys($vars)),
            array_values($vars),
        ));
        $body = (string) file_get_contents($file->getPathname());
        foreach ($vars as $k => $v) {
            $body = str_replace("{{{$k}}}", $v, $body);
        }
        if (preg_match('/\{\{[A-Z_]+\}\}/', $body, $m)) {
            throw new \RuntimeException("unreplaced placeholder {$m[0]} in {$rel}");
        }
        writeFile("{$target}/{$rel}", $body);
    }
}

/**
 * The pinned Strauss phar, downloaded once into $cacheDir and checked against
 * the digest GitHub publishes for the release asset.
 */
function strauss(string $cacheDir): string
{
    $phar = "{$cacheDir}/strauss-" . STRAUSS_VERSION . '.phar';
    if (!is_file($phar) || hash_file('sha256', $phar) !== STRAUSS_SHA256) {
        if (!is_dir($cacheDir)) {
            mkdir($cacheDir, 0777, true);
        }
        $url = 'https://github.com/BrianHenryIE/strauss/releases/download/' . STRAUSS_VERSION . '/strauss.phar';
        sh(['curl', '-fsSL', '-o', $phar, $url]);
    }
    $hash = hash_file('sha256', $phar);
    if ($hash !== STRAUSS_SHA256) {
        throw new \RuntimeException("strauss.phar digest mismatch: {$hash}");
    }

    return $phar;
}

/**
 * The Strauss block a plugin copies (docs/packaging.md shows the same one).
 *
 * @param list<string> $packages
 * @return array<string, mixed>
 */
function straussConfig(string $namespacePrefix, string $classmapPrefix, array $packages, array $excludePackages = []): array
{
    $config = [
        'target_directory' => 'vendor-prefixed',
        'namespace_prefix' => $namespacePrefix,
        'classmap_prefix' => $classmapPrefix,
        'packages' => $packages,
        'delete_vendor_packages' => true,
        'include_modified_date' => false,
        'exclude_from_copy' => [
            'file_patterns' => [
                // Ship each package's runtime code, licence and manifest only.
                '#^getgrav/grav-db-kit/(?!src/|composer\.json$|LICENSE$)#',
                '#^yetidevworks/yetisearch/(?!src/|composer\.json$|LICENSE$)#',
            ],
        ],
    ];
    if ($excludePackages !== []) {
        $config['exclude_from_copy']['packages'] = $excludePackages;
        $config['exclude_from_prefix'] = ['packages' => $excludePackages];
    }

    return $config;
}

/**
 * The scripts block a plugin copies (docs/packaging.md shows the same one):
 * fetch the pinned phar when it is missing, check its digest, prefix, then
 * rebuild vendor/autoload.php without the packages Strauss removed.
 *
 * @return array<string, mixed>
 */
function straussScripts(): array
{
    $url = 'https://github.com/BrianHenryIE/strauss/releases/download/' . STRAUSS_VERSION . '/strauss.phar';

    return [
        'strauss:install' => "test -f bin/strauss.phar || (mkdir -p bin && curl -fsSL -o bin/strauss.phar {$url})",
        'strauss:verify' => "@php -r \"if (hash_file('sha256', 'bin/strauss.phar') !== '" . STRAUSS_SHA256
            . "') { fwrite(STDERR, 'bin/strauss.phar is not Strauss " . STRAUSS_VERSION . "' . PHP_EOL); exit(1); }\"",
        'prefix' => [
            '@strauss:install',
            '@strauss:verify',
            '@php bin/strauss.phar',
            '@composer dump-autoload',
        ],
        'post-install-cmd' => ['@prefix'],
        'post-update-cmd' => ['@prefix'],
    ];
}

/**
 * The .gitignore lines a prefixed plugin uses: Forum Pro's (commit only the
 * autoloader out of vendor/) plus the Strauss phar and the alias file Strauss
 * writes for development. vendor-prefixed/ is committed whole.
 */
const PLUGIN_GITIGNORE = <<<'TXT'
    /vendor/*
    !/vendor/autoload.php
    !/vendor/composer/
    /vendor/composer/autoload_aliases.php
    /bin/strauss.phar
    composer.lock

    TXT;

/**
 * composer.json for one fake plugin.
 *
 * @param list<array<string, mixed>> $repositories
 * @param array<string, string> $require
 * @param array<string, string> $requireDev
 * @param array<string, mixed>|null $strauss null for an unprefixed plugin
 * @return array<string, mixed>
 */
function pluginComposer(string $slug, string $ns, array $repositories, array $require, array $requireDev, ?array $strauss): array
{
    $json = [
        'name' => "packaging-check/{$slug}",
        'type' => 'grav-plugin',
        'version' => '1.0.0',
        'license' => 'MIT',
        'repositories' => $repositories,
        'require' => ['php' => '>=8.3'] + $require,
        'require-dev' => $requireDev,
        'autoload' => [
            'psr-4' => ["{$ns}\\" => 'classes/'],
            'classmap' => ["{$slug}.php"],
        ],
        'config' => ['sort-packages' => true, 'platform-check' => false],
    ];
    if ($requireDev === []) {
        unset($json['require-dev']);
    }
    if ($strauss !== null) {
        $json['autoload']['files'] = ['vendor-prefixed/autoload.php'];
        $json['extra'] = ['strauss' => $strauss];
        $json['scripts'] = straussScripts();
    }

    return $json;
}

/**
 * Copy what a release of the plugin would contain into $target. A prefixed
 * plugin ships what git would commit under PLUGIN_GITIGNORE (asked of git
 * itself, so the ignore rules are exercised, not re-implemented). An
 * unprefixed one ships everything but bin/, the way KahunaCart commits its
 * whole vendor/.
 *
 * @return list<string> the shipped paths, relative to the plugin
 */
function ship(string $pluginDir, string $target, bool $prefixed): array
{
    if ($prefixed) {
        writeFile("{$pluginDir}/.gitignore", PLUGIN_GITIGNORE);
        sh(['git', 'init', '-q'], $pluginDir);
        sh(['git', 'add', '-A'], $pluginDir);
        $files = preg_split('/\R/', trim(sh(['git', 'ls-files'], $pluginDir)));
    } else {
        $files = [];
        $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($pluginDir, \FilesystemIterator::SKIP_DOTS));
        foreach ($it as $file) {
            $rel = substr($file->getPathname(), \strlen($pluginDir) + 1);
            if (!str_starts_with($rel, 'bin/')) {
                $files[] = $rel;
            }
        }
    }
    sort($files);
    foreach ($files as $rel) {
        writeFile("{$target}/{$rel}", (string) file_get_contents("{$pluginDir}/{$rel}"));
    }

    return $files;
}

/**
 * A path repository that mirrors (never symlinks) the package, pinned to a
 * version. Mirroring matters: with delete_vendor_packages on, Strauss removes
 * vendor/<package> after copying it, and through a symlink that would be the
 * source checkout.
 *
 * @return array<string, mixed>
 */
function pathRepo(string $url, string $package, string $version): array
{
    return [
        'type' => 'path',
        'url' => $url,
        'options' => ['symlink' => false, 'versions' => [$package => $version]],
    ];
}

function encodeJson(array $data): string
{
    return json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n";
}

/**
 * Places under $root that still name $namespace unprefixed: a class name Strauss could not rewrite (built at runtime, or spelt in a
 * way it did not recognise), which would resolve to another plugin's copy or
 * to nothing. Reads PHP tokens, so it looks at qualified names, namespace
 * declarations and string literals, and ignores comments. Composer's
 * generated files under composer/ are skipped.
 *
 * @return list<string> "path:line: text", paths relative to $root
 */
function unprefixedReferences(string $root, string $namespace): array
{
    $isUnprefixed = static function (string $name) use ($namespace): bool {
        $name = ltrim(str_replace('\\\\', '\\', $name), '\\');

        return $name === $namespace || str_starts_with($name, $namespace . '\\');
    };

    $hits = [];
    $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS));
    foreach ($it as $file) {
        $path = $file->getPathname();
        if ($file->getExtension() !== 'php' || str_contains($path, '/composer/')) {
            continue;
        }
        $tokens = token_get_all((string) file_get_contents($path));
        $afterNamespace = false;
        foreach ($tokens as $t) {
            if (!\is_array($t) || $t[0] === T_WHITESPACE) {
                continue;
            }
            [$id, $text, $line] = $t;
            $flag = match ($id) {
                T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED => $isUnprefixed($text),
                T_STRING => $afterNamespace && $isUnprefixed($text),
                T_CONSTANT_ENCAPSED_STRING, T_ENCAPSED_AND_WHITESPACE
                    => str_contains($text, '\\') && $isUnprefixed(trim($text, '\'"')),
                default => false,
            };
            $afterNamespace = $id === T_NAMESPACE;
            if ($flag) {
                $hits[] = substr($path, \strlen($root) + 1) . ":{$line}: {$text}";
            }
        }
    }

    return $hits;
}

/**
 * Files under $prefixedSrc that differ from $originalSrc once the prefix is
 * taken out again (in its single- and double-backslash spellings), plus files
 * only one side has. Empty means Strauss changed nothing but the names.
 *
 * @return list<string>
 */
function diffAfterUnprefixing(string $prefixedSrc, string $originalSrc, string $prefix): array
{
    $list = static function (string $root): array {
        $out = [];
        $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS));
        foreach ($it as $f) {
            $out[] = substr($f->getPathname(), \strlen($root) + 1);
        }
        sort($out);

        return $out;
    };
    $a = $list($prefixedSrc);
    $b = $list($originalSrc);
    $problems = array_map(static fn ($f) => "only in one copy: {$f}", [...array_diff($a, $b), ...array_diff($b, $a)]);
    $spellings = [$prefix => '', str_replace('\\', '\\\\', $prefix) => ''];
    foreach (array_intersect($a, $b) as $rel) {
        $prefixed = strtr((string) file_get_contents("{$prefixedSrc}/{$rel}"), $spellings);
        if ($prefixed !== file_get_contents("{$originalSrc}/{$rel}")) {
            $problems[] = "{$rel} differs beyond the prefix";
        }
    }

    return $problems;
}
