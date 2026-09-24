# Packaging: bundling the kit in a plugin

Every plugin that uses grav-db-kit ships its own copy of it, renamed into the plugin's namespace with [Strauss](https://github.com/BrianHenryIE/strauss). This page is the recipe a plugin copies, the reasons behind each line, and how to check the result. `tools/packaging-check/` in this repository runs the recipe end to end and proves it works.

## Why every copy is prefixed

Grav loads the Composer autoloader of every enabled plugin into one PHP process. It initialises plugins in folder-name order (`Plugins::__construct()` sorts them), moves each plugin's loader behind Grav core's, and keeps the plugins in that order among themselves. When two plugins both map `TrilbyMedia\GravDbKit\`, the one whose folder name sorts first supplies the classes to both. Nothing warns: the other plugin just runs on code it was not built against.

The packaging check shows this with two fake plugins bundling the kit unprefixed, one at 1.0.0 and one at 1.0.1:

| Loaded first | What the other plugin gets |
|---|---|
| plugin-c (1.0.0) | plugin-d asks for 1.0.1, silently gets 1.0.0 from plugin-c's `vendor/`, its migrations and jobs run on the old copy, and the first call to a 1.0.1 method dies with `Call to undefined method` |
| plugin-d (1.0.1) | plugin-c asks for 1.0.0 and silently runs on 1.0.1, a version it was never tested against |

This already happens on real sites. KahunaCart bundles `yetidevworks/yetisearch` 2.3.6 unprefixed and yetisearch-pro bundles 2.4.0 unprefixed. `kahunacart` sorts before `yetisearch-pro`, so on a site with both, yetisearch-pro runs on 2.3.6.

Strauss fixes it by rewriting the bundled copy's namespace at build time. In Helpdesk Pro, `TrilbyMedia\GravDbKit\Database\Connection` becomes `Grav\Plugin\HelpdeskPro\Vendor\TrilbyMedia\GravDbKit\Database\Connection`, and YetiSearch's `YetiSearch\YetiSearch` becomes `Grav\Plugin\HelpdeskPro\Vendor\YetiSearch\YetiSearch`. Each plugin's copy is then a different set of classes, so any number of versions can load side by side. The packaging check loads kit 1.0.0 and 1.0.1 under two prefixes in one process, points both at the same SQLite file with different table prefixes, and each copy migrates its own tables and runs its own job without touching the other's.

## Which Strauss, and how it runs

Strauss **0.30.0**, as the release phar, downloaded by a Composer script into `bin/strauss.phar` and checked against the release's SHA-256 before it runs. It is a build tool only: nothing from it ships, and a site never runs Composer or Strauss.

Strauss can also be installed with `composer require --dev brianhenryie/strauss`. That is the wrong choice here:

- A release build is `composer install --no-dev`, and `--no-dev` leaves dev packages uninstalled, so a Composer-installed Strauss is not there when `post-install-cmd` calls it.
- Strauss brings its own dependency tree (Symfony Console, Flysystem, PHP-Parser and more) into the plugin's dependency resolution, where it can hold back or conflict with the plugin's own requirements.
- Strauss's own README recommends the phar.

What the phar costs: the first build needs network access to GitHub, and upgrading Strauss is a manual edit (the version in the URL and the digest) rather than `composer update`. Both are small, and the pin means every developer and every release build prefixes with exactly the same Strauss.

## composer.json

This is Helpdesk Pro's. Another plugin changes the names, the prefixes and the package list.

```json
{
    "name": "trilbymedia/grav-plugin-helpdesk-pro",
    "type": "grav-plugin",
    "repositories": [
        {
            "type": "path",
            "url": "../grav-db-kit",
            "options": {
                "symlink": false,
                "versions": {
                    "getgrav/grav-db-kit": "1.0.0"
                }
            }
        }
    ],
    "require": {
        "php": ">=8.3",
        "getgrav/grav-db-kit": "^1.0",
        "yetidevworks/yetisearch": "^2.4"
    },
    "require-dev": {
        "phpunit/phpunit": "^11.5"
    },
    "autoload": {
        "psr-4": {
            "Grav\\Plugin\\HelpdeskPro\\": "classes/"
        },
        "classmap": [
            "helpdesk-pro.php"
        ],
        "files": [
            "vendor-prefixed/autoload.php"
        ]
    },
    "autoload-dev": {
        "psr-4": {
            "Grav\\Plugin\\HelpdeskPro\\Tests\\": "tests/"
        }
    },
    "extra": {
        "strauss": {
            "target_directory": "vendor-prefixed",
            "namespace_prefix": "Grav\\Plugin\\HelpdeskPro\\Vendor\\",
            "classmap_prefix": "Grav_Plugin_HelpdeskPro_Vendor_",
            "packages": [
                "getgrav/grav-db-kit",
                "yetidevworks/yetisearch"
            ],
            "delete_vendor_packages": true,
            "include_modified_date": false,
            "exclude_from_copy": {
                "file_patterns": [
                    "#^getgrav/grav-db-kit/(?!src/|composer\\.json$|LICENSE$)#",
                    "#^yetidevworks/yetisearch/(?!src/|composer\\.json$|LICENSE$)#"
                ],
                "packages": [
                    "psr/log"
                ]
            },
            "exclude_from_prefix": {
                "packages": [
                    "psr/log"
                ]
            }
        }
    },
    "scripts": {
        "strauss:install": "test -f bin/strauss.phar || (mkdir -p bin && curl -fsSL -o bin/strauss.phar https://github.com/BrianHenryIE/strauss/releases/download/0.30.0/strauss.phar)",
        "strauss:verify": "@php -r \"if (hash_file('sha256', 'bin/strauss.phar') !== '08c1a8e553594745c22294e158129005fd11ed09ed452d7d4f48566f38c66c96') { fwrite(STDERR, 'bin/strauss.phar is not Strauss 0.30.0' . PHP_EOL); exit(1); }\"",
        "prefix": [
            "@strauss:install",
            "@strauss:verify",
            "@php bin/strauss.phar",
            "@composer dump-autoload"
        ],
        "post-install-cmd": [
            "@prefix"
        ],
        "post-update-cmd": [
            "@prefix"
        ],
        "test": "phpunit",
        "test:unit": "phpunit --testsuite Unit",
        "test:integration": "phpunit --testsuite Integration"
    },
    "config": {
        "sort-packages": true,
        "platform-check": false
    }
}
```

What each part is for:

| Setting | Why |
|---|---|
| path repository, `symlink: false` | Until the kit is on Packagist. Mirroring gives the plugin a snapshot, so nothing Strauss does in `vendor/` can reach the kit checkout. Strauss 0.30.0 does unlink a symlinked package rather than delete through it, but a mirror means that never matters. Drop the whole `repositories` entry once the kit is published. |
| `versions` | A path repository otherwise takes its version from the kit's git branch (`dev-develop`), which `^1.0` does not match. Set it to the kit version being developed. |
| `autoload.files` | Makes `vendor/autoload.php` load `vendor-prefixed/autoload.php`, so the plugin, its tests and its CLI all get both from the one `require` they already do. |
| `target_directory` | The committed output directory. |
| `namespace_prefix` | The plugin's own namespace plus `Vendor\`. It must be unique to the plugin; two plugins sharing a prefix are back to sharing classes. |
| `classmap_prefix` | For classes in the global namespace. Neither the kit nor YetiSearch has any; set it anyway so a future dependency that does gets a name tied to the plugin rather than one Strauss infers. |
| `packages` | Only these (and their dependencies) are prefixed. Without it Strauss takes everything in `require`. `require-dev` is never included. |
| `delete_vendor_packages` | Removes the unprefixed copy from `vendor/` after prefixing, and `@composer dump-autoload` then drops it from `vendor/autoload.php`, so the plugin cannot load the unprefixed names by accident. |
| `include_modified_date: false` | Defensive. If Strauss ever stamps the build date into the headers of the files it edits, every rebuild becomes a diff of the whole directory. Strauss 0.30.0 writes no headers with this config, so today it changes nothing. |
| `exclude_from_copy.file_patterns` | Ship only `src/`, `composer.json` and `LICENSE` of each package. The kit's path repository mirrors its tests, docs and CI files, and Strauss 0.25+ copies every file of a package unless told otherwise. |
| `psr/log` in `exclude_from_copy` and `exclude_from_prefix` | YetiSearch type-hints `Psr\Log\LoggerInterface`. Grav core ships psr/log (3.0.2 in Grav 2.0) and its logger implements the unprefixed interface. Prefixed, YetiSearch refuses any logger built on Grav's copy (tried: `YetiSearch::__construct(): Argument #2 ($logger) must be of type ?PluginY\Vendor\Psr\Log\LoggerInterface, Psr\Log\NullLogger given`). Left alone, it uses Grav's copy. |
| `strauss:install` / `strauss:verify` | Download the pinned phar once, and refuse to run any other file. |
| `@composer dump-autoload` | Rebuild `vendor/autoload.php` after Strauss removed the unprefixed packages. |

`update_call_sites` stays off (the default). It rewrites the plugin's own source files in place, which makes the source say one thing before a build and another after. The plugin writes the prefixed names itself (see below).

## .gitignore

The plugin ships as a zip with no Composer step on the user's site, so the built autoloader and the prefixed copy are committed. These are Forum Pro's lines plus two for Strauss:

```gitignore
/vendor/*
!/vendor/autoload.php
!/vendor/composer/
/vendor/composer/autoload_aliases.php
/bin/strauss.phar
composer.lock
```

- `vendor-prefixed/` is committed whole. Nothing ignores it, so it needs no line (the `!/vendor-prefixed/` in the Helpdesk Pro spec is harmless and can stay).
- `vendor/composer/autoload_aliases.php` is a file Strauss writes so development tools can still resolve the old names. It is never loaded by the release autoloader and must not ship.
- `vendor/psr/` stays out with the rest of `vendor/`. The committed `vendor/composer/autoload_psr4.php` still maps `Psr\Log\` to it; on a site that directory is absent and Grav core's loader, which runs first, answers for `Psr\Log` anyway.

## The plugin's code

The main plugin file does not change:

```php
public function autoload(): ClassLoader
{
    return require __DIR__ . '/vendor/autoload.php';
}
```

Grav keeps and re-orders the loader this returns. The `files` entry has already registered the prefixed loader by then. That loader is classmap-authoritative (Strauss's default) and sits in front of the others, so for any class outside the prefix it costs one array lookup.

Everything the plugin writes uses the prefixed names, including migration files:

```php
use Grav\Plugin\HelpdeskPro\Vendor\TrilbyMedia\GravDbKit\Database\Connection;
use Grav\Plugin\HelpdeskPro\Vendor\TrilbyMedia\GravDbKit\Database\Dialect\Dialect;
use Grav\Plugin\HelpdeskPro\Vendor\TrilbyMedia\GravDbKit\Database\Migration;
use Grav\Plugin\HelpdeskPro\Vendor\YetiSearch\YetiSearch;
```

The prefix sits inside the plugin's own PSR-4 root (`Grav\Plugin\HelpdeskPro\`). That is fine: the prefixed loader answers before the plugin's loader would look for a `classes/Vendor/` directory.

## Commands

| When | Run |
|---|---|
| First checkout, or after changing dependencies | `composer install` / `composer update`. Both prefix afterwards. |
| Prefix again without touching dependencies | `composer install` (not `composer prefix` on its own: with `delete_vendor_packages` the originals are already gone, so a second Strauss run empties the prefixed autoloader) |
| Before committing `vendor-prefixed/` or `vendor/` changes | `composer install --no-dev`, commit, then `composer install` to get phpunit back |

Only commit vendor changes from a `--no-dev` install. A dev install writes phpunit into `vendor/composer/` and flips `'dev' => true` in `vendor-prefixed/composer/installed.php`; the prefixed code itself is identical either way (the packaging check compares the two).

## Verifying

In this repository, the packaging check builds fake plugins through exactly the config above and loads them the way Grav does:

```
php tools/packaging-check/run.php                  # kit and YetiSearch (Packagist for YetiSearch)
php tools/packaging-check/run.php --no-yetisearch  # kit only; offline once the phar is cached
php tools/packaging-check/run.php --keep           # keep tools/packaging-check/build/ to look at
YETISEARCH_PATH=../yetisearch php tools/packaging-check/run.php   # prefix a local YetiSearch checkout
```

It needs php with pdo_sqlite, sqlite3 and mbstring, plus composer, git and curl. What it checks:

- Two plugins with the kit prefixed (1.0.0 under `PluginA\Vendor\`, 1.0.1 under `PluginB\Vendor\`) load in one process. Each sees its own version and loads it from its own `vendor-prefixed/`, runs its own `Migrator` and `JobQueue`/`JobRunner` against its own table prefix in one shared SQLite file, and the 1.0.1-only method exists in one copy and not the other.
- Two plugins with the kit unprefixed collide in both load orders, as in the table at the top.
- YetiSearch 2.4 prefixed together with kit 1.0.1 (the config above) indexes and searches next to an unprefixed YetiSearch 2.3.6 plus kit 1.0.0 (KahunaCart's arrangement), accepts an unprefixed PSR-3 logger, and has the 2.4 semantic interface that the 2.3.6 copy lacks.
- The runtime checks load each plugin from a copy holding only what git would commit under the `.gitignore` above, so they prove the committed files are enough on a site.
- A token scan of every prefixed copy finds no unprefixed `TrilbyMedia\GravDbKit` or `YetiSearch` name left in code or strings (it first proves it catches a planted one), and each prefixed kit copy, with the prefix taken out again, is the kit source byte for byte.
- The committed autoloader maps neither the unprefixed packages, nor the dev package, nor the alias file; a dev install leaves `vendor-prefixed/` unchanged apart from the dev flag.
- The `extra.strauss` block, the Strauss scripts, `autoload.files` and the `.gitignore` shown on this page are the ones the check ran. Edit this page and the check together.

In a plugin, `VendorIsolationTest` covers the same ground without building anything:

- no file in `classes/`, `migrations/`, `cli/` or the main plugin file names `TrilbyMedia\GravDbKit\` or `YetiSearch\` without the plugin's `Vendor\` prefix;
- `vendor/composer/autoload_psr4.php` and `autoload_static.php` map neither `TrilbyMedia\GravDbKit\` nor `YetiSearch\`, and mention neither phpunit nor `autoload_aliases`;
- `vendor-prefixed/autoload.php` exists and `class_exists('Grav\Plugin\HelpdeskPro\Vendor\TrilbyMedia\GravDbKit\Database\Connection')` is true, while `class_exists('TrilbyMedia\GravDbKit\Database\Connection', false)` is false after loading only the plugin's autoloader;
- no directory under `vendor/` other than `composer/` is tracked by git.

## What prefixing cannot reach

Strauss rewrites every spelling of a namespace it finds in PHP source: `namespace` and `use` lines, qualified names, and class names inside strings, single or double quoted, with or without a leading backslash, even a namespace string concatenated with a variable (`'TrilbyMedia\\GravDbKit\\' . $x`). `__NAMESPACE__` already answers the prefixed name at runtime. All of that was tried against 0.30.0. What it cannot reach is a name pieced together from fragments smaller than the namespace (`'TrilbyMedia' . '\\' . 'GravDbKit'`), and class names outside PHP source: in YAML or JSON config, in database rows, or inside serialized objects in a cache. The kit has none of those; keep it that way. The packaging check's token scan fails when an unprefixed name survives in a prefixed copy.

Two effects worth knowing:

- `JobRunner` records a failed job as `$e::class . ': ' . $e->getMessage()`, so a kit exception in `last_error` reads `Grav\Plugin\HelpdeskPro\Vendor\TrilbyMedia\GravDbKit\…`. That is only text.
- Static state is per copy. The kit's `Migrator` file cache and YetiSearch's `StemmerFactory` cache belong to one plugin's classes, which is the point.

YetiSearch 2.3.6 and 2.4.0 both prefix cleanly: with the prefix taken out again, the prefixed `src/` is byte for byte the tagged source. `bin/yetisearch` is left out by the copy rule (it defines global functions and requires `../vendor/autoload.php`), and psr/log is left unprefixed as described above.

## Switching Forum Pro and KahunaCart later

Both plugins carry in-tree copies of this code today (see the README's "Switching an existing plugin"). Moving them to the kit is also the moment to prefix, and KahunaCart should prefix its YetiSearch in the same release, which ends the collision with yetisearch-pro.

Forum Pro has no add-ons that import its database classes, so it only has to follow this page and rename its imports.

KahunaCart is harder. Its add-ons (bookings, licenses, newsletters, rentals, shipping, subscriptions and the rest) implement `Grav\Plugin\KahunaCart\Database\Migration` and type-hint `Grav\Plugin\KahunaCart\Database\Connection`. After the switch the real classes are `Grav\Plugin\KahunaCart\Vendor\TrilbyMedia\GravDbKit\Database\…`. The options, none built yet:

1. **Release in lockstep.** Every add-on changes its imports to KahunaCart's prefixed names and raises its KahunaCart dependency to the switching version. Simple, and nothing extra stays in KahunaCart, but a site that updates KahunaCart without its add-ons breaks until they catch up.
2. **`class_alias()` shims for the old names.** KahunaCart keeps `Grav\Plugin\KahunaCart\Database\Connection`, `Migration`, `Dialect\Dialect` and the rest alive as aliases of the prefixed kit classes for a release or two, so add-ons keep working unchanged and move to the new names on their own schedule. Aliases of a class, an interface or an enum are the same type, so `implements` and type hints keep working. Register them all at once, from a file in the plugin's `autoload.files`, not lazily from an autoloader on first use: PHP does not run the autoloader for a parameter type check, so a closure typed `function (Connection $c)` with an old name fails when it is called unless the alias already exists, and add-on migration steps are exactly such closures. KahunaCart 1.3 does this in `legacy-aliases.php` for 23 names, at the cost of loading those small classes on every request. Caveats: `Old::class` is still the old string while `get_class()` answers the prefixed name, so anything that compares or stores class-name strings needs checking; and the shims need an end date, or the old names become permanent API.
3. **Add-ons never bundle the kit themselves.** Whichever option above, an add-on uses the host's copy through the host's prefixed names. An add-on with its own prefixed kit would have its own `Connection` class and could not share KahunaCart's connection or migration tracking.

The recommendation is option 2 for one or two KahunaCart releases, with option 3 as the rule for every add-on.
