<?php

declare(strict_types=1);

namespace TrilbyMedia\GravDbKit\Tests\Integration\Jobs;

use PHPUnit\Framework\TestCase;
use TrilbyMedia\GravDbKit\Jobs\JobHandler;
use TrilbyMedia\GravDbKit\Jobs\JobHandlerRegistry;
use TrilbyMedia\GravDbKit\Jobs\JobQueue;
use TrilbyMedia\GravDbKit\Jobs\JobRunner;
use TrilbyMedia\GravDbKit\Testing\FrozenClock;
use TrilbyMedia\GravDbKit\Tests\Jobs\Support\JobsDatabase;

/**
 * The runner's own knobs, beyond what the deferral, control and deadline
 * tests already walk it through: the job cap Forum Pro's worker had, taking
 * handlers from a registry, and the worker id it writes into `locked_by`.
 */
final class JobRunnerTest extends TestCase
{
    public function testAPassStopsAtMaxJobs(): void
    {
        $queue = $this->queue();
        for ($i = 0; $i < 5; $i++) {
            $queue->enqueue('a', ['n' => $i]);
        }

        $handler = new TallyHandler();
        $runner = new JobRunner($queue, 'w');
        $runner->register('a', $handler);

        self::assertSame(['processed' => 2, 'failed' => 0, 'retrying' => 0, 'deferred' => 0], $runner->run(50, 2));
        self::assertSame(2, $handler->calls);
        self::assertSame(3, $queue->stats()['pending'], 'the rest wait for the next pass');

        self::assertSame(3, $runner->run(50)['processed'], 'no cap is no cap');
    }

    /**
     * kahunacart.com, 2026-11-16: a Slack lookup timed out, the notification
     * job threw and went through on its retry a minute later, but the pass
     * that threw exited 1 and paged the owner through GravGuard. The runner now
     * says which failures will run again, so a worker can tell a blip from a
     * job that has given up.
     */
    public function testAFailureWithAttemptsLeftIsCountedAsRetrying(): void
    {
        $clock = new FrozenClock(1_700_000_000);
        $queue = new JobQueue(JobsDatabase::fresh(), JobsDatabase::tables(), 300, null, $clock);
        $queue->enqueue('boom', [], 0, 2);
        $queue->enqueue('boom', [], 0, 1);

        $runner = new JobRunner($queue, 'w', $clock);
        $runner->register('boom', new ThrowingHandler());

        $first = $runner->run(1);
        self::assertSame(2, $first['failed']);
        self::assertSame(1, $first['retrying'], 'the one-attempt job ran out; the other backs off');

        $clock->advance(JobQueue::backoff(1));
        $second = $runner->run(1);
        self::assertSame(1, $second['failed']);
        self::assertSame(0, $second['retrying'], 'its last attempt');
        self::assertSame(2, $queue->stats()['failed']);
    }

    public function testHandlersComeFromARegistry(): void
    {
        $registry = new JobHandlerRegistry();
        $registry->register('a', new TallyHandler());
        $registry->register('b', new TallyHandler());

        $runner = (new JobRunner($this->queue(), 'w'))->registerAll($registry);

        self::assertSame(['a', 'b'], $runner->types());
    }

    public function testTheHostWorkerIdFitsTheLockColumn(): void
    {
        $id = JobRunner::hostWorkerId();

        self::assertLessThanOrEqual(64, \strlen($id));
        self::assertStringEndsWith(':' . getmypid(), $id);
    }

    /** A Clock object is as good as a callable, and the runner writes its worker id. */
    public function testTheRunnerAcceptsAClockAndStampsItsWorkerId(): void
    {
        $clock = new FrozenClock(1_700_000_000);
        $queue = new JobQueue(JobsDatabase::fresh(), JobsDatabase::tables(), 300, null, $clock);
        $id = $queue->enqueue('a');

        $runner = new JobRunner($queue, 'host-a:123', $clock);
        $runner->register('a', new TallyHandler());
        $runner->run(1);

        $row = $queue->find($id);
        self::assertNotNull($row);
        self::assertSame('host-a:123', $row['locked_by']);
        self::assertSame(1_700_000_000, (int)$row['completed_at']);
        self::assertSame($queue, $runner->queue());
    }

    private function queue(): JobQueue
    {
        return new JobQueue(JobsDatabase::fresh(), JobsDatabase::tables(), 300, null, new FrozenClock());
    }
}

final class ThrowingHandler implements JobHandler
{
    public function handle(array $payload): void
    {
        throw new \RuntimeException('Resolving timed out after 5001 milliseconds');
    }
}

final class TallyHandler implements JobHandler
{
    public int $calls = 0;

    public function handle(array $payload): void
    {
        $this->calls++;
    }
}
