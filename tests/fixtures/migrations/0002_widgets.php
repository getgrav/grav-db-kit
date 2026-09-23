<?php

declare(strict_types=1);

use TrilbyMedia\GravDbKit\Database\Connection;
use TrilbyMedia\GravDbKit\Database\Dialect\Dialect;
use TrilbyMedia\GravDbKit\Database\Migration;

/** A domain table with a seed row, so snapshots have rows to carry. */
return new class implements Migration {
    public function name(): string
    {
        return '0002_widgets';
    }

    public function steps(Dialect $d): array
    {
        return [
            'create_kit_t_widgets' => function (Connection $c) use ($d): void {
                if (!$d->tableExists($c->pdo(), 'kit_t_widgets')) {
                    $c->run("CREATE TABLE kit_t_widgets (
                        id {$d->primaryKey()},
                        sku VARCHAR(64) NOT NULL,
                        name VARCHAR(190) NOT NULL,
                        body {$d->textLong()} NULL,
                        CONSTRAINT uq_kit_t_widgets_sku UNIQUE (sku)
                    ) {$d->tableOptions()}");
                }
            },
            'seed_kit_t_widgets' => function (Connection $c): void {
                if ($c->fetchValue('SELECT id FROM kit_t_widgets WHERE sku = ?', ['seed']) === null) {
                    $c->insert('kit_t_widgets', ['sku' => 'seed', 'name' => 'Seeded widget']);
                }
            },
        ];
    }
};
