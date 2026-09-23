<?php

declare(strict_types=1);

namespace {{NS}};

use {{KIT}}\Database\Connection;
use {{KIT}}\Jobs\JobHandler;

final class NoteHandler implements JobHandler
{
    public function __construct(private readonly Connection $db)
    {
    }

    public function handle(array $payload): void
    {
        $this->db->insert('{{TABLE}}_notes', ['body' => (string) $payload['text'], 'created_at' => time()]);
    }
}
