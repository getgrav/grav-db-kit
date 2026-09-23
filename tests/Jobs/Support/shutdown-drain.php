<?php

declare(strict_types=1);

/**
 * A request, run as its own PHP process, that drains from shutdown hooks.
 *
 * Driven by InlineDrainShutdownTest. What only a real process end can show:
 * the drain run from `register_shutdown_function`, twice over — once where
 * Grav's `onShutdown` would call it and once from the fallback — with nothing
 * reaching stdout but the response itself.
 *
 *   php shutdown-drain.php <sqlite file> <counter file> <mode>
 *
 * Modes:
 *   both         Grav's shutdown reaches onShutdown and drains, and the
 *                fallback, appended behind it, drains again.
 *   grav-throws  Grav's shutdown throws before onShutdown (the session
 *                close bug), the exception handler reports it without
 *                ending the process, and the fallback drains alone.
 */

use TrilbyMedia\GravDbKit\Database\ConnectionFactory;
use TrilbyMedia\GravDbKit\Database\KitTables;
use TrilbyMedia\GravDbKit\Jobs\InlineDrain;
use TrilbyMedia\GravDbKit\Jobs\JobHandler;
use TrilbyMedia\GravDbKit\Jobs\JobQueue;
use TrilbyMedia\GravDbKit\Jobs\JobRunner;
use TrilbyMedia\GravDbKit\Jobs\PendingDrain;
use TrilbyMedia\GravDbKit\Schema\InfraTables;

require \dirname(__DIR__, 3) . '/vendor/autoload.php';

[, $file, $counter, $mode] = $argv;

ini_set('display_errors', '1');
error_reporting(E_ALL);

$db = ConnectionFactory::sqlite($file);
$tables = KitTables::withPrefix('kitjobs');
InfraTables::jobs($db->dialect(), $tables)($db);

$pending = new PendingDrain();
$queue = new JobQueue($db, $tables, trigger: $pending);

$runner = new JobRunner($queue, 'shutdown');
$runner->register('noisy', new class($counter) implements JobHandler {
    public function __construct(private readonly string $counter)
    {
    }

    public function handle(array $payload): void
    {
        file_put_contents($this->counter, "ran\n", FILE_APPEND);
        // Everything a careless handler can do to a response already sent.
        echo 'handler output';
        trigger_error('a warning with display_errors on', E_USER_WARNING);
    }
});

// Each hook builds its own drain, as a plugin's two hooks would.
$drain = static function () use ($pending, $runner): void {
    (new InlineDrain($pending, $runner, 3, static function (string $line): void {
        echo 'log line: ' . $line;
    }, true))->run();
};

set_exception_handler(static function (\Throwable $e): void {
    // Grav's handler logs; this one says nothing at all on stdout.
    fwrite(STDERR, 'exception handler: ' . $e->getMessage() . "\n");
});

// The fallback, registered from inside a shutdown function so it lands
// behind Grav's own shutdown rather than in front of it.
register_shutdown_function(static function () use ($drain): void {
    register_shutdown_function($drain);
});

// Grav::shutdown(), registered once the response is ready.
register_shutdown_function(static function () use ($drain, $mode): void {
    if ($mode === 'grav-throws') {
        throw new \RuntimeException('Session::close() failed to reopen the session');
    }

    // onShutdown
    $drain();
});

$queue->enqueue('noisy');

echo 'response';
