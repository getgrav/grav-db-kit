<?php

declare(strict_types=1);

use TrilbyMedia\GravDbKit\Database\Dialect\Dialect;
use TrilbyMedia\GravDbKit\Database\KitTables;
use TrilbyMedia\GravDbKit\Database\Migration;
use TrilbyMedia\GravDbKit\Schema\InfraTables;

/**
 * The infrastructure tables every plugin's first migration creates, under the
 * kit's default `kit_*` names.
 */
return new class implements Migration {
    public function name(): string
    {
        return '0001_infra';
    }

    public function steps(Dialect $d): array
    {
        $t = new KitTables();

        return [
            'create_kit_kv' => InfraTables::kv($d, $t),
            'create_kit_jobs' => InfraTables::jobs($d, $t),
            'create_kit_rate_limits' => InfraTables::rateLimits($d, $t),
        ];
    }
};
