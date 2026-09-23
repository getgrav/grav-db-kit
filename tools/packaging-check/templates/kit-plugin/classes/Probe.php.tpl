<?php

declare(strict_types=1);

namespace {{NS}};

use {{KIT}}\Database\ConnectionFactory;
use {{KIT}}\Database\KitOptions;
use {{KIT}}\Database\KitTables;
use {{KIT}}\Database\Migrator;
use {{KIT}}\Jobs\JobQueue;
use {{KIT}}\Jobs\JobRunner;
use {{KIT}}\PackagingMarker;

/**
 * What the plugin does with its bundled kit: open the database, migrate it,
 * queue a job and run it, and say which copy of the kit answered.
 */
final class Probe
{
    /** @return array<string, mixed> */
    public static function run(string $dbPath): array
    {
        $options = new KitOptions(savepointPrefix: '{{TABLE}}_sp_');
        $db = ConnectionFactory::sqlite($dbPath, $options);
        $tables = KitTables::withPrefix('{{TABLE}}');

        $migrator = new Migrator($db, [\dirname(__DIR__) . '/migrations'], $tables, $options);
        $applied = [];
        $migrator->migrate(static function (string $migration, string $step) use (&$applied): void {
            $applied[] = "{$migration}:{$step}";
        });

        $queue = new JobQueue($db, $tables);
        $runner = new JobRunner($queue, '{{SLUG}}-worker');
        $runner->register('note.write', new NoteHandler($db));
        $jobId = $queue->enqueue('note.write', ['text' => 'hello from {{SLUG}}']);
        $ran = $runner->run(5);
        $job = $queue->find($jobId);

        $extra = null;
        try {
            // Only kit 1.0.1 has this method.
            $extra = PackagingMarker::addedIn101();
        } catch (\Error $e) {
            $extra = 'Error: ' . $e->getMessage();
        }

        return [
            'plugin' => '{{SLUG}}',
            'expects' => '{{EXPECTS}}',
            'marker' => PackagingMarker::VERSION,
            'marker_class' => PackagingMarker::class,
            'marker_file' => (new \ReflectionClass(PackagingMarker::class))->getFileName(),
            'queue_file' => (new \ReflectionClass(JobQueue::class))->getFileName(),
            'added_in_101' => $extra,
            'applied' => $applied,
            'up_to_date' => $migrator->isUpToDate(),
            'run' => $ran,
            'job_completed' => $job !== null && $job['completed_at'] !== null,
            'job_state' => $job !== null ? $queue->stateOf($job) : null,
            'notes' => array_column($db->fetchAll('SELECT body FROM {{TABLE}}_notes ORDER BY id'), 'body'),
            'migrations_rows' => array_map(
                static fn (array $r): string => $r['migration'] . ':' . $r['step'],
                $db->fetchAll('SELECT migration, step FROM {{TABLE}}_migrations ORDER BY id'),
            ),
        ];
    }
}
