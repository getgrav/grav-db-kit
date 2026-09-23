<?php

declare(strict_types=1);

namespace TrilbyMedia\GravDbKit\Tests\Integration\Jobs;

use PHPUnit\Framework\TestCase;
use TrilbyMedia\GravDbKit\Database\Connection;
use TrilbyMedia\GravDbKit\Jobs\DaemonHeartbeat;
use TrilbyMedia\GravDbKit\Jobs\JobDaemon;
use TrilbyMedia\GravDbKit\Jobs\JobDeadline;
use TrilbyMedia\GravDbKit\Jobs\JobHandler;
use TrilbyMedia\GravDbKit\Jobs\JobQueue;
use TrilbyMedia\GravDbKit\Jobs\JobRunner;
use TrilbyMedia\GravDbKit\Tests\Jobs\Support\JobsDatabase;

/**
 * The worker as a process that stays up, and every way it is meant to stop.
 *
 * A daemon is only worth having if it dies well. Everything here is a way it
 * can be asked to stand down — a limit reached, a signal, a hung handler, a
 * database that went away — and what an operator's supervisor sees when it
 * does. Time, sleep and memory are all injected, so an hour-long limit and a
 * memory ceiling are both reached in a millisecond and nothing here waits.
 */
final class JobDaemonTest extends TestCase
{
    private int $now = 3_000_000;

    /** @var list<int> */
    private array $slept = [];

    public function testItDrainsTheQueueAndStopsAtMaxJobs(): void
    {
        $db = JobsDatabase::fresh();
        $queue = $this->queue($db);
        for ($i = 0; $i < 5; $i++) {
            $queue->enqueue('order.confirmation_email', ['order_id' => $i]);
        }

        $handler = new CountingHandler();
        $result = $this->daemon($queue, ['order.confirmation_email' => $handler], ['max_jobs' => 3])->run();

        self::assertSame(JobDaemon::STOP_MAX_JOBS, $result['stop']);
        self::assertSame(0, $result['exit_code'], 'a limit is not a failure');
        self::assertSame(3, $result['processed']);
        self::assertSame(3, $handler->calls);
        self::assertSame(
            2,
            (int)$db->fetchValue('SELECT COUNT(*) FROM ' . JobsDatabase::table() . ' WHERE completed_at IS NULL'),
            'the rest wait for the next process'
        );
    }

    /** An empty queue is polled, not spun on. */
    public function testAnEmptyQueueIsPolledAndTheLoopGivesUpAtMaxTime(): void
    {
        $queue = $this->queue(JobsDatabase::fresh());

        $result = $this->daemon($queue, [], ['max_time' => 5, 'poll' => 1])->run();

        self::assertSame(JobDaemon::STOP_MAX_TIME, $result['stop']);
        self::assertSame(0, $result['exit_code']);
        self::assertSame(0, $result['jobs']);
        self::assertSame([1, 1, 1, 1, 1], $this->slept, 'one second at a time, five times');
    }

    /**
     * A handler that fatals with an \Error rather than an exception.
     *
     * The claim already spent an attempt, so three of these retire the job to
     * the failed bucket — and the loop carries on with the next one, because a
     * worker that stopped on a bad job would stop on it again every minute.
     */
    public function testAHandlerThatThrowsAnErrorIsFailedAndTheLoopCarriesOn(): void
    {
        $db = JobsDatabase::fresh();
        $queue = $this->queue($db);
        $badId = $queue->enqueue('order.confirmation_email', []);
        $queue->enqueue('order.shipped_email', []);

        $good = new CountingHandler();
        $result = $this->daemon($queue, [
            'order.confirmation_email' => new Fatals(),
            'order.shipped_email' => $good,
        ], ['max_jobs' => 2])->run();

        self::assertSame(0, $result['exit_code']);
        self::assertSame(1, $result['failed']);
        self::assertSame(1, $result['processed']);
        self::assertSame(1, $good->calls, 'the loop got past the bad job');

        $row = $db->fetchRow('SELECT attempts, last_error FROM ' . JobsDatabase::table() . ' WHERE id = ?', [$badId]);
        self::assertSame(1, (int)$row['attempts']);
        self::assertStringContainsString('Error', (string)$row['last_error']);
    }

    /** Three crashes and the job is in the failed bucket, where somebody will read it. */
    public function testThreeCrashesRetireAJobToTheFailedBucket(): void
    {
        $db = JobsDatabase::fresh();
        $queue = $this->queue($db);
        $id = $queue->enqueue('order.confirmation_email', []);

        for ($attempt = 0; $attempt < 3; $attempt++) {
            $this->daemon($queue, ['order.confirmation_email' => new Fatals()], ['max_jobs' => 1])->run();
            // Past the backoff the failure wrote.
            $this->now += 3600;
        }

        $row = $db->fetchRow('SELECT * FROM ' . JobsDatabase::table() . ' WHERE id = ?', [$id]);
        self::assertSame(3, (int)$row['attempts']);
        self::assertSame(JobQueue::STATE_FAILED, $queue->stateOf($row, $this->now));
    }

    /**
     * A database that goes away mid-job.
     *
     * Logged once, exit code 1, and no attempt to reconnect: the claimed row is
     * released by the stale-lock window, and the supervisor starts a process
     * that is definitely clean. See JobDaemon's class docblock.
     */
    public function testALostDatabaseConnectionStopsTheLoopWithCodeOne(): void
    {
        $db = JobsDatabase::fresh();
        $queue = $this->queue($db);
        $queue->enqueue('order.confirmation_email', []);

        $logged = [];
        $daemon = new JobDaemon(
            $queue,
            $this->runner($queue, ['order.confirmation_email' => new BreaksTheConnection($db)]),
            'test-daemon',
            null,
            ['max_jobs' => 5, 'poll' => 1],
            null,
            static function (string $line) use (&$logged): void {
                $logged[] = $line;
            },
            fn (): int => $this->now,
            $this->sleeper(),
        );

        $result = $daemon->run();

        self::assertSame(JobDaemon::STOP_DATABASE, $result['stop']);
        self::assertSame(1, $result['exit_code']);
        self::assertCount(1, $logged, 'logged once, not once per retry');
        self::assertStringContainsString('database went away', $logged[0]);
    }

    /** A job that overran holds things PHP cannot take back, so the process goes. */
    public function testAJobThatPassesItsDeadlineStopsTheLoopWithCodeOne(): void
    {
        $db = JobsDatabase::fresh();
        $queue = $this->queue($db);
        $id = $queue->enqueue('order.confirmation_email', []);
        $queue->enqueue('order.shipped_email', []);

        $clock = 0.0;
        // The after-the-fact mechanism on purpose: it is the one every FastCGI
        // build gets, and the one whose behaviour is worth pinning down.
        $deadline = new JobDeadline(2, static function () use (&$clock): float {
            return $clock;
        }, false);

        $runner = new JobRunner($queue, 'test-daemon', fn (): int => $this->now);
        $runner->register('order.confirmation_email', new SlowHandler(function () use (&$clock): void {
            $clock += 30.0;
        }));
        $runner->register('order.shipped_email', new CountingHandler());
        $runner->withDeadline($deadline);

        $result = (new JobDaemon(
            $queue,
            $runner,
            'test-daemon',
            null,
            ['max_jobs' => 5],
            null,
            null,
            fn (): int => $this->now,
            $this->sleeper(),
        ))->run();

        self::assertSame(JobDaemon::STOP_JOB_TIMEOUT, $result['stop']);
        self::assertSame(1, $result['exit_code']);
        self::assertSame(1, $result['failed']);
        self::assertSame(1, $result['jobs'], 'it stopped after the job that overran');

        $row = $db->fetchRow('SELECT last_error FROM ' . JobsDatabase::table() . ' WHERE id = ?', [$id]);
        self::assertStringContainsString('deadline', (string)$row['last_error']);
    }

    /** Memory climbing past the ceiling ends the process rather than the host's. */
    public function testCrossingTheMemoryCeilingStandsTheProcessDown(): void
    {
        $db = JobsDatabase::fresh();
        $queue = $this->queue($db);
        for ($i = 0; $i < 5; $i++) {
            $queue->enqueue('order.confirmation_email', []);
        }

        $inUse = 10_000_000;
        $result = (new JobDaemon(
            $queue,
            $this->runner($queue, ['order.confirmation_email' => new CountingHandler()]),
            'test-daemon',
            null,
            ['max_jobs' => 100, 'max_memory' => 0.8],
            null,
            null,
            fn (): int => $this->now,
            $this->sleeper(),
            static function () use (&$inUse): int {
                $inUse += 30_000_000;

                return $inUse;
            },
            100_000_000,
        ))->run();

        self::assertSame(JobDaemon::STOP_MEMORY, $result['stop']);
        self::assertSame(0, $result['exit_code'], 'standing down on purpose is not a failure');
        self::assertGreaterThan(0, $result['processed']);
        self::assertLessThan(5, $result['processed'], 'it stopped before the queue was empty');
    }

    /** A stop signal is honoured after the job in hand, not during it. */
    public function testAStopSignalFinishesTheJobInHandAndExitsCleanly(): void
    {
        $db = JobsDatabase::fresh();
        $queue = $this->queue($db);
        $queue->enqueue('order.confirmation_email', []);
        $queue->enqueue('order.confirmation_email', []);

        $daemon = null;
        $handler = new SlowHandler(static function () use (&$daemon): void {
            // The supervisor's SIGTERM, arriving while a job is running.
            $daemon?->stop();
        });

        $daemon = new JobDaemon(
            $queue,
            $this->runner($queue, ['order.confirmation_email' => $handler]),
            'test-daemon',
            null,
            ['max_jobs' => 100],
            null,
            null,
            fn (): int => $this->now,
            $this->sleeper(),
        );

        $result = $daemon->run();

        self::assertSame(JobDaemon::STOP_SIGNAL, $result['stop']);
        self::assertSame(0, $result['exit_code']);
        self::assertSame(1, $result['processed'], 'the job in hand finished');
        self::assertSame(
            1,
            (int)$db->fetchValue('SELECT COUNT(*) FROM ' . JobsDatabase::table() . ' WHERE completed_at IS NULL'),
            'the second was left for the next process'
        );
    }

    /** Two workers over one queue, which is what a daemon beside cron is. */
    public function testTwoRunnersOverOneQueueProcessEachJobOnce(): void
    {
        $db = JobsDatabase::fresh();
        $queue = $this->queue($db);
        for ($i = 1; $i <= 6; $i++) {
            $queue->enqueue('order.confirmation_email', ['order_id' => $i]);
        }

        $handler = new CountingHandler();
        // The daemon takes three, then a cron tick arrives for the rest.
        $this->daemon($queue, ['order.confirmation_email' => $handler], ['max_jobs' => 3])->run();

        $cron = $this->runner($queue, ['order.confirmation_email' => $handler]);
        $cron->run(5);

        self::assertSame(6, $handler->calls, 'six jobs, six runs');
        self::assertSame(
            6,
            (int)$db->fetchValue('SELECT COUNT(*) FROM ' . JobsDatabase::table() . ' WHERE completed_at IS NOT NULL')
        );
        self::assertSame(
            [1, 1, 1, 1, 1, 1],
            array_map('intval', array_column(
                $db->fetchAll('SELECT attempts FROM ' . JobsDatabase::table() . ' ORDER BY id'),
                'attempts'
            )),
            'one attempt each — nothing was claimed twice'
        );
    }

    /** The sweeps run on their own timer, not once per email. */
    public function testHousekeepingRunsOnItsTimerRatherThanPerJob(): void
    {
        $db = JobsDatabase::fresh();
        $queue = $this->queue($db);
        for ($i = 0; $i < 4; $i++) {
            $queue->enqueue('order.confirmation_email', []);
        }

        $sweeps = 0;
        $result = (new JobDaemon(
            $queue,
            $this->runner($queue, ['order.confirmation_email' => new CountingHandler()]),
            'test-daemon',
            null,
            ['max_jobs' => 4, 'housekeeping' => 60],
            static function () use (&$sweeps): void {
                $sweeps++;
            },
            null,
            fn (): int => $this->now,
            $this->sleeper(),
        ))->run();

        self::assertSame(4, $result['processed']);
        self::assertSame(1, $sweeps, 'once at the start, and not again inside the same minute');
    }

    /** A clean stop takes the heartbeat with it; nothing warns about a worker nobody wanted. */
    public function testACleanStopClearsTheHeartbeat(): void
    {
        $db = JobsDatabase::fresh();
        $queue = $this->queue($db);
        $queue->enqueue('order.confirmation_email', []);

        $heartbeat = new SpyHeartbeat();
        $daemon = new JobDaemon(
            $queue,
            $this->runner($queue, ['order.confirmation_email' => new CountingHandler()]),
            'test-daemon',
            $heartbeat,
            ['max_jobs' => 1],
            null,
            null,
            fn (): int => $this->now,
            $this->sleeper(),
        );

        self::assertNull($heartbeat->last, 'a site that never ran one shows nothing');

        $daemon->run();

        self::assertNotEmpty($heartbeat->beats, 'it beat while it ran, the first beat forced');
        self::assertTrue($heartbeat->beats[0]['force']);
        self::assertNull($heartbeat->last, 'and nothing is left behind by a clean stop');
        self::assertSame(1, $heartbeat->cleared);
    }

    /** A crash leaves the last beat behind, which is what the warning is made of. */
    public function testACrashLeavesTheLastBeatBehind(): void
    {
        $db = JobsDatabase::fresh();
        $queue = $this->queue($db);
        $queue->enqueue('order.confirmation_email', []);

        $daemon = new JobDaemon(
            $queue,
            $this->runner($queue, ['order.confirmation_email' => new BreaksTheConnection($db)]),
            'test-daemon',
            $heartbeat = new SpyHeartbeat(),
            ['max_jobs' => 1],
            null,
            null,
            fn (): int => $this->now,
            $this->sleeper(),
        );

        $result = $daemon->run();

        self::assertSame(1, $result['exit_code']);
        self::assertSame(0, $heartbeat->cleared, 'a crash does not clear the beat');
        self::assertNotNull($heartbeat->last);
        self::assertSame($this->now, $heartbeat->last['now']);
    }

    /** With no clock of its own the daemon keeps the queue's time, as the runner does. */
    public function testTheDaemonTakesTheQueuesClockByDefault(): void
    {
        $queue = $this->queue(JobsDatabase::fresh());

        $result = (new JobDaemon(
            $queue,
            $this->runner($queue, []),
            'test-daemon',
            null,
            ['max_time' => 3, 'poll' => 1],
            sleep: $this->sleeper(),
        ))->run();

        self::assertSame(JobDaemon::STOP_MAX_TIME, $result['stop']);
        self::assertSame([1, 1, 1], $this->slept, 'the sleeper moved the queue\'s clock, and the daemon saw it');
    }

    /** Sweeps that throw are tidying gone wrong, not a reason to stop the worker. */
    public function testHousekeepingThatThrowsIsLoggedAndTheLoopCarriesOn(): void
    {
        $db = JobsDatabase::fresh();
        $queue = $this->queue($db);
        $queue->enqueue('order.confirmation_email', []);

        $logged = [];
        $handler = new CountingHandler();
        $result = (new JobDaemon(
            $queue,
            $this->runner($queue, ['order.confirmation_email' => $handler]),
            'test-daemon',
            null,
            ['max_jobs' => 1],
            static function (): void {
                throw new \RuntimeException('retention sweep broke');
            },
            static function (string $line) use (&$logged): void {
                $logged[] = $line;
            },
            fn (): int => $this->now,
            $this->sleeper(),
        ))->run();

        self::assertSame(0, $result['exit_code']);
        self::assertSame(1, $handler->calls);
        self::assertStringContainsString('retention sweep broke', $logged[0] ?? '');
    }

    /** What a plugin passes as the ceiling, read from memory_limit. */
    public function testThePhpMemoryLimitIsReadInBytes(): void
    {
        $before = ini_get('memory_limit');
        try {
            ini_set('memory_limit', '768M');
            self::assertSame(768 * 1024 * 1024, JobDaemon::phpMemoryLimit());
            ini_set('memory_limit', '-1');
            self::assertSame(-1, JobDaemon::phpMemoryLimit());
        } finally {
            ini_set('memory_limit', (string)$before);
        }
    }

    private function queue(Connection $db): JobQueue
    {
        return new JobQueue($db, JobsDatabase::tables(), 300, null, fn (): int => $this->now);
    }

    /** @param array<string, JobHandler> $handlers */
    private function runner(JobQueue $queue, array $handlers): JobRunner
    {
        $runner = new JobRunner($queue, 'test-daemon', fn (): int => $this->now, $this->sleeper());
        foreach ($handlers as $type => $handler) {
            $runner->register($type, $handler);
        }

        return $runner;
    }

    /**
     * @param array<string, JobHandler> $handlers
     * @param array<string, mixed> $options
     */
    private function daemon(JobQueue $queue, array $handlers, array $options): JobDaemon
    {
        return new JobDaemon(
            $queue,
            $this->runner($queue, $handlers),
            'test-daemon',
            null,
            $options,
            null,
            null,
            fn (): int => $this->now,
            $this->sleeper(),
        );
    }

    /** Sleeping moves the clock rather than the wall clock; nothing here waits. */
    private function sleeper(): callable
    {
        return function (int $seconds): void {
            $this->slept[] = $seconds;
            $this->now += $seconds;
        };
    }
}

final class CountingHandler implements JobHandler
{
    public int $calls = 0;

    public function handle(array $payload): void
    {
        $this->calls++;
    }
}

/** An \Error, not an exception — the form a real fatal takes. */
final class Fatals implements JobHandler
{
    public function handle(array $payload): void
    {
        throw new \Error('Call to a member function on null');
    }
}

/** Does something, on a callback the test controls. */
final class SlowHandler implements JobHandler
{
    /** @var callable(): void */
    private $during;

    /** @param callable(): void $during */
    public function __construct(callable $during)
    {
        $this->during = $during;
    }

    public function handle(array $payload): void
    {
        ($this->during)();
    }
}

/**
 * The database going away under a running job.
 *
 * It takes the jobs table with it rather than throwing a PDOException, because
 * a handler that throws is a handler failure — the runner catches those and
 * writes `last_error`. What the daemon has to survive is the *settling*
 * failing: the row cannot be completed, and it cannot be marked failed either,
 * which is exactly what a connection that has gone away looks like from here.
 */
final class BreaksTheConnection implements JobHandler
{
    public function __construct(private readonly Connection $db)
    {
    }

    public function handle(array $payload): void
    {
        $this->db->run('DROP TABLE ' . JobsDatabase::table());
    }
}

/** Remembers every beat, and whether the loop cleared it on the way out. */
final class SpyHeartbeat implements DaemonHeartbeat
{
    /** @var list<array{started_at: int, jobs: int, now: int|null, force: bool}> */
    public array $beats = [];

    /** @var array{started_at: int, jobs: int, now: int|null, force: bool}|null */
    public ?array $last = null;

    public int $cleared = 0;

    public function beat(int $startedAt, int $jobsDone, ?int $now = null, ?int $memoryBytes = null, bool $force = false): void
    {
        $this->last = ['started_at' => $startedAt, 'jobs' => $jobsDone, 'now' => $now, 'force' => $force];
        $this->beats[] = $this->last;
    }

    public function clear(): void
    {
        $this->cleared++;
        $this->last = null;
    }
}
