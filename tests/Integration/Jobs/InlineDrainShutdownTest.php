<?php

declare(strict_types=1);

namespace TrilbyMedia\GravDbKit\Tests\Integration\Jobs;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use TrilbyMedia\GravDbKit\Database\ConnectionFactory;

/**
 * The drain run from real shutdown hooks, in a PHP process of its own.
 *
 * Only a process that actually ends can show this: the drain called from
 * `register_shutdown_function` — once where Grav's onShutdown would call it
 * and once from the fallback behind it, or from the fallback alone when
 * Grav's shutdown throws first — runs the job exactly once and leaves
 * nothing on stdout but the response. SQLite whatever the run's engine: what
 * is under test is PHP's shutdown, not SQL.
 */
final class InlineDrainShutdownTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        if (!\extension_loaded('pdo_sqlite')) {
            self::markTestSkipped('pdo_sqlite is needed for the child process.');
        }

        $this->dir = sys_get_temp_dir() . '/gravdbkit-shutdown-' . bin2hex(random_bytes(6));
        mkdir($this->dir, 0o777, true);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->dir . '/*') ?: [] as $file) {
            @unlink($file);
        }
        @rmdir($this->dir);
    }

    /** @return array<string, array{0: string}> */
    public static function modes(): array
    {
        return [
            'onShutdown and the fallback both drain' => ['both'],
            'Grav shutdown throws, the fallback drains alone' => ['grav-throws'],
        ];
    }

    #[DataProvider('modes')]
    public function testTheJobRunsOnceAndOnlyTheResponseIsPrinted(string $mode): void
    {
        $database = $this->dir . '/jobs.sqlite';
        $counter = $this->dir . '/ran.txt';

        $process = proc_open(
            [\PHP_BINARY, \dirname(__DIR__, 2) . '/Jobs/Support/shutdown-drain.php', $database, $counter, $mode],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes
        );
        self::assertIsResource($process);
        $stdout = (string)stream_get_contents($pipes[1]);
        $stderr = (string)stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $exit = proc_close($process);

        self::assertSame('response', $stdout, 'nothing the drain did reached the client');
        self::assertSame(0, $exit, $stderr);
        self::assertSame("ran\n", (string)@file_get_contents($counter), 'the handler ran exactly once');

        $row = ConnectionFactory::sqlite($database)->fetchRow('SELECT * FROM kitjobs_jobs');
        self::assertNotNull($row);
        self::assertNotNull($row['completed_at']);
        self::assertSame(1, (int)$row['attempts']);

        if ($mode === 'grav-throws') {
            self::assertStringContainsString('failed to reopen the session', $stderr);
        }
    }
}
