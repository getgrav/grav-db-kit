<?php

declare(strict_types=1);

namespace {{NS}};

use Composer\Autoload\ClassLoader;

/**
 * Stand-in for a Grav plugin's main file. Grav requires this file, builds the
 * plugin object, then calls autoload() and keeps the ClassLoader it returns.
 * vendor/autoload.php pulls vendor-prefixed/autoload.php in through the
 * composer.json "files" entry, so this one line loads both.
 */
final class {{CLASS}}
{
    public function autoload(): ClassLoader
    {
        return require __DIR__ . '/vendor/autoload.php';
    }
}
