<?php

declare(strict_types=1);

namespace {{NS}};

use Composer\Autoload\ClassLoader;

final class {{CLASS}}
{
    public function autoload(): ClassLoader
    {
        return require __DIR__ . '/vendor/autoload.php';
    }
}
