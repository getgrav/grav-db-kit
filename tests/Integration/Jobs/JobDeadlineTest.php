<?php

declare(strict_types=1);

namespace TrilbyMedia\GravDbKit\Tests\Integration\Jobs;

use PHPUnit\Framework\TestCase;
use TrilbyMedia\GravDbKit\Jobs\JobDeadline;
use TrilbyMedia\GravDbKit\Jobs\JobHandler;
use TrilbyMedia\GravDbKit\Jobs\JobQueue;
use TrilbyMedia\GravDbKit\Jobs\JobRunner;
use TrilbyMedia\GravDbKit\Jobs\JobTimedOut;
use TrilbyMedia\GravDbKit\Tests\Jobs\Support\JobsDatabase;

/**
 * How long one job may take, and what happens when it takes longer.
 *
 * Both mechanisms ship, because a site gets whichever one its PHP build can
 * manage: an alarm that stops a handler mid-call where pcntl is present, and a
 * look at the clock afterwards where it is not. Neither is a secret from the
 * operator — a daemon command prints which one is running — and both are proven
 * here, whichever machine the suite runs on.
 */
final class JobDeadlineTest extends TestCase
{
    private int $now = 4_000_000;

    /** No deadline is a deadline of zero, and it stays out of the way. */
    public function testZeroSecondsMeansNoDeadlineAtAll(): void
    {
        $deadline = new JobDeadline(0);

        self::assertSame(JobDeadline::MECHANISM_OFF, $deadline->mechanism());

        $deadline->start();
        $deadline->finish();

        self::assertFalse($deadline->tripped());
    }

    /** A handler that finished in time is not touched. */
    public function testAJobInsideItsDeadlinePassesThrough(): void
    {
        $clock = 0.0;
        $deadline = new JobDeadline(10, static function () use (&$clock): float {
            return $clock;
        }, false);

        $deadline->start();
        $clock += 3.0;
        $deadline->finish();

        self::assertFalse($deadline->tripped());
    }

    /** And one that overran is told so, in a message that says what to do. */
    public function testAnOverrunIsNoticedAfterTheFact(): void
    {
        $clock = 0.0;
        $deadline = new JobDeadline(10, static function () use (&$clock): float {
            return $clock;
        }, false);

        $deadline->start();
        $clock += 45.0;

        try {
            $deadline->finish();
            self::fail('the overrun should have been noticed');
        } catch (JobTimedOut $e) {
            self::assertSame(10, $e->seconds);
            self::assertSame(45, $e->elapsed);
            self::assertStringContainsString('Raise the job timeout setting', $e->getMessage());
        }

        self::assertTrue($deadline->tripped());
    }

    /**
     * A handler that threw on its own keeps its own error.
     *
     * The runner disarms the deadline rather than letting it relabel a real
     * failure as a timeout: "Provider refused the charge" is worth reading and
     * "it took too long" is not, when both are true.
     */
    public function testAHandlerThatThrewKeepsItsOwnError(): void
    {
        $queue = new JobQueue(JobsDatabase::fresh(), JobsDatabase::tables(), 300, null, fn (): int => $this->now);
        $id = $queue->enqueue('order.confirmation_email', []);

        $clock = 0.0;
        $runner = new JobRunner($queue, 'test', fn (): int => $this->now);
        $runner->register('order.confirmation_email', new ThrowsAfterAWhile(function () use (&$clock): void {
            $clock += 90.0;
        }));
        $runner->withDeadline(new JobDeadline(10, static function () use (&$clock): float {
            return $clock;
        }, false));

        $runner->run(5);

        $error = (string)$queue->find($id)['last_error'];
        self::assertStringContainsString('the provider refused', $error);
        self::assertStringNotContainsString('deadline', $error);
    }

    /** An overrun ends as a failure, so retry and the failed bucket work as usual. */
    public function testAnOverrunIsRecordedAsAFailedJob(): void
    {
        $queue = new JobQueue(JobsDatabase::fresh(), JobsDatabase::tables(), 300, null, fn (): int => $this->now);
        $id = $queue->enqueue('order.confirmation_email', []);

        $clock = 0.0;
        $runner = new JobRunner($queue, 'test', fn (): int => $this->now);
        $runner->register('order.confirmation_email', new Dawdles(function () use (&$clock): void {
            $clock += 90.0;
        }));
        $runner->withDeadline(new JobDeadline(10, static function () use (&$clock): float {
            return $clock;
        }, false));

        $result = $runner->run(5);

        self::assertSame(1, $result['failed']);
        $row = $queue->find($id);
        self::assertNull($row['completed_at']);
        self::assertSame(1, (int)$row['attempts'], 'an overrun costs an attempt like any failure');
        self::assertStringContainsString('deadline', (string)$row['last_error']);
    }

    /** Where pcntl is present the alarm is the mechanism, and it really fires. */
    public function testTheAlarmStopsAHandlerWhereThisBuildHasPcntl(): void
    {
        if (!JobDeadline::alarmAvailable()) {
            self::markTestSkipped('This PHP build has no pcntl, so the after-the-fact path is the only one it can use.');
        }

        $deadline = new JobDeadline(1, null, true);
        self::assertSame(JobDeadline::MECHANISM_ALARM, $deadline->mechanism());

        $stoppedAt = null;
        $deadline->start();
        try {
            // A blocked call, the way a socket waiting on a provider is one.
            usleep(3_000_000);
            $deadline->finish();
        } catch (JobTimedOut $e) {
            $stoppedAt = $e->seconds;
        } finally {
            $deadline->stop();
        }

        self::assertSame(1, $stoppedAt, 'the alarm interrupted the wait rather than letting it finish');
        self::assertTrue($deadline->tripped());
    }

    /** The mechanism a host will actually use, for the line the command prints. */
    public function testTheMechanismIsReportedHonestly(): void
    {
        self::assertSame(JobDeadline::MECHANISM_ALARM, (new JobDeadline(120, null, true))->mechanism());
        self::assertSame(JobDeadline::MECHANISM_AFTER_THE_FACT, (new JobDeadline(120, null, false))->mechanism());
        self::assertSame(JobDeadline::MECHANISM_OFF, (new JobDeadline(0, null, true))->mechanism());
    }
}

/** Takes a while and then fails on its own terms. */
final class ThrowsAfterAWhile implements JobHandler
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

        throw new \RuntimeException('the provider refused the charge');
    }
}

/** Takes a while and then finishes, which is the overrun case. */
final class Dawdles implements JobHandler
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
