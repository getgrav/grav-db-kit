<?php

declare(strict_types=1);

namespace TrilbyMedia\GravDbKit\Tests\Unit;

use PHPUnit\Framework\TestCase;
use TrilbyMedia\GravDbKit\Support\Process;

final class ProcessTest extends TestCase
{
    public function testItIsThisProcess(): void
    {
        self::assertSame(getmypid(), Process::id());
        self::assertSame(gethostname(), Process::host());
        self::assertSame(gethostname() . ':' . getmypid(), Process::label());
    }

    /**
     * A hardened PHP-FPM pool disables getmypid() and gethostname(). The lease
     * owner and the job worker id are built from them, and a plain call there
     * was a fatal error, so they are run here in a PHP that has neither.
     */
    public function testItFallsBackWhenTheFunctionsAreDisabled(): void
    {
        $autoload = var_export(dirname(__DIR__, 2) . '/vendor/autoload.php', true);
        $code = "require {$autoload};"
            . 'echo json_encode(['
            . 'function_exists("getmypid"),'
            . '\TrilbyMedia\GravDbKit\Support\Process::label(),'
            . '(new \TrilbyMedia\GravDbKit\Database\KitOptions())->owner(),'
            . '\TrilbyMedia\GravDbKit\Jobs\JobRunner::hostWorkerId(),'
            . ']);';

        $command = escapeshellarg(PHP_BINARY)
            . ' -n -d disable_functions=getmypid,getmyuid,getmygid,gethostname -r '
            . escapeshellarg($code) . ' 2>&1';
        exec($command, $output, $status);

        self::assertSame(0, $status, implode("\n", $output));
        self::assertSame([false, 'unknown:0', 'unknown:0', 'unknown:0'], json_decode(implode('', $output), true));
    }
}
