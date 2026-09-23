<?php

declare(strict_types=1);

use {{KIT}}\Database\Connection;
use {{KIT}}\Database\Dialect\Dialect;
use {{KIT}}\Database\KitTables;
use {{KIT}}\Database\Migration;
use {{KIT}}\Schema\InfraTables;

return new class implements Migration {
    public function name(): string
    {
        return '0001_infra';
    }

    public function steps(Dialect $d): array
    {
        $t = KitTables::withPrefix('{{TABLE}}');

        return [
            'create_{{TABLE}}_kv' => InfraTables::kv($d, $t),
            'create_{{TABLE}}_jobs' => InfraTables::jobs($d, $t),
            'create_{{TABLE}}_notes' => function (Connection $c) use ($d): void {
                if (!$d->tableExists($c->pdo(), '{{TABLE}}_notes')) {
                    $c->run("CREATE TABLE {{TABLE}}_notes (id {$d->primaryKey()}, body TEXT NOT NULL, created_at BIGINT NOT NULL) {$d->tableOptions()}");
                }
            },
        ];
    }
};
