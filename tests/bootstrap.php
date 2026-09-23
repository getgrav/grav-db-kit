<?php

declare(strict_types=1);

/**
 * The kit is Grav-free, so tests need nothing but the autoloader.
 *
 * The memory floor matches KahunaCart's: raised to 512M rather than set, so a
 * CI box that already allows more keeps it and one running unlimited (-1) is
 * left alone. The Migrator's file cache is what keeps the suite well under it.
 */
(static function (): void {
    $limit = trim((string)ini_get('memory_limit'));
    if ($limit === '' || $limit === '-1') {
        return;
    }

    $units = ['k' => 1024, 'm' => 1024 ** 2, 'g' => 1024 ** 3];
    $bytes = (int)$limit * ($units[strtolower(substr($limit, -1))] ?? 1);

    if ($bytes < 512 * 1024 * 1024) {
        ini_set('memory_limit', '512M');
    }
})();

require __DIR__ . '/../vendor/autoload.php';
