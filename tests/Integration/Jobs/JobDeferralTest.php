<?php

declare(strict_types=1);

namespace TrilbyMedia\GravDbKit\Tests\Integration\Jobs;

use PHPUnit\Framework\TestCase;
use TrilbyMedia\GravDbKit\Database\Connection;
use TrilbyMedia\GravDbKit\Jobs\ContextualJobHandler;
use TrilbyMedia\GravDbKit\Jobs\DeferJob;
use TrilbyMedia\GravDbKit\Jobs\JobHandler;
use TrilbyMedia\GravDbKit\Jobs\JobQueue;
use TrilbyMedia\GravDbKit\Jobs\JobRunner;
use TrilbyMedia\GravDbKit\Tests\Jobs\Support\JobsDatabase;

/**
 * A handler saying "not yet", and the worker pass that waits for it.
 *
 * This is the machinery under KahunaCart's order emails: an email whose
 * panels are still being filled in by another job steps aside, and the pass it
 * stepped aside in comes back for it a few seconds later rather than leaving
 * somebody waiting for the next scheduler tick a minute away.
 *
 * Time is a fake shared by the queue and the runner — the sleep moves it, so
 * the tests prove the waiting without doing any of it.
 */
final class JobDeferralTest extends TestCase
{
    private int $now = 1_000_000;

    /** @var list<int> */
    private array $slept = [];

    public function testADeferredJobIsHandedBackWithoutCostingAnAttempt(): void
    {
        $db = JobsDatabase::fresh();
        $queue = $this->queue($db);
        $id = $queue->enqueue('order.confirmation_email', ['order_id' => 1]);

        $runner = $this->runner($queue);
        $runner->register('order.confirmation_email', new AlwaysDefers());

        $result = $runner->run(50);

        $row = $queue->find($id);
        self::assertSame(0, (int)$row['attempts'], 'a deferral is not an attempt');
        self::assertNull($row['completed_at']);
        self::assertNull($row['last_error'], 'a deferral is not a failure');
        self::assertGreaterThanOrEqual(1_000_005, (int)$row['run_after']);
        self::assertSame(0, $result['failed']);
        self::assertGreaterThan(0, $result['deferred']);
        self::assertSame([5], array_unique($this->slept), 'five seconds at a time');
    }

    /**
     * The point of the whole exercise: the email defers, the five seconds pass,
     * and it goes out — all inside one worker invocation, because by then the
     * job it was waiting on has run.
     */
    public function testAPassWaitsForADeferredJobAndFinishesItInTheSameRun(): void
    {
        $db = JobsDatabase::fresh();
        $queue = $this->queue($db);
        $emailId = $queue->enqueue('order.confirmation_email', ['order_id' => 1]);

        $runner = $this->runner($queue);
        // Ready on the second look, the way an email is once the add-on's
        // issue job has run.
        $runner->register('order.confirmation_email', new DefersOnce());

        $result = $runner->run(50);

        $row = $queue->find($emailId);
        self::assertNotNull($row['completed_at'], 'the email went out in the same pass');
        self::assertSame(1, (int)$row['attempts'], 'only the send that actually ran was counted');
        self::assertSame(['processed' => 1, 'failed' => 0, 'deferred' => 1], $result);
        self::assertSame([5], $this->slept);
    }

    /**
     * The pass runs both jobs in id order, so the work an add-on queued first
     * has finished before the email that describes it is rendered.
     */
    public function testAPassRunsQueuedWorkInIdOrder(): void
    {
        $queue = $this->queue(JobsDatabase::fresh());
        $queue->enqueue('licenses.issue', ['order_id' => 1]);
        $queue->enqueue('order.confirmation_email', ['order_id' => 1]);

        $recorder = new RecordsOrder();
        $runner = $this->runner($queue);
        $runner->register('licenses.issue', $recorder);
        $runner->register('order.confirmation_email', $recorder);

        $runner->run(50);

        self::assertSame(['licenses.issue', 'order.confirmation_email'], $recorder->types);
    }

    /**
     * A job scheduled beyond this worker's budget belongs to the next tick, and
     * a pass that waited for it would hold a process open for nothing.
     */
    public function testAPassDoesNotWaitForAJobDueAfterItsBudget(): void
    {
        $queue = $this->queue(JobsDatabase::fresh());
        $queue->enqueue('cart.abandoned_sweep', [], $this->now + 3600);

        $runner = $this->runner($queue);
        $runner->register('cart.abandoned_sweep', new AlwaysDefers());

        $result = $runner->run(50);

        self::assertSame([], $this->slept);
        self::assertSame(['processed' => 0, 'failed' => 0, 'deferred' => 0], $result);
    }

    /** A single wait never runs past ten seconds, however far out the job is. */
    public function testASingleWaitIsCapped(): void
    {
        $queue = $this->queue(JobsDatabase::fresh());
        $queue->enqueue('order.confirmation_email', [], $this->now + 30);

        $runner = $this->runner($queue);
        $runner->register('order.confirmation_email', new RecordsOrder());

        $runner->run(50);

        self::assertSame([10, 10, 10], $this->slept, 'ten seconds at a time until the job comes due');
    }

    /**
     * A handler that never heard of the job row keeps working exactly as it
     * did: JobRunner only offers the row to one that asks for it.
     */
    public function testAPlainHandlerIsStillCalledWithItsPayloadAlone(): void
    {
        $queue = $this->queue(JobsDatabase::fresh());
        $queue->enqueue('legacy.thing', ['a' => 1]);

        $handler = new PlainHandler();
        $runner = $this->runner($queue);
        $runner->register('legacy.thing', $handler);

        $runner->run(5);

        self::assertSame([['a' => 1]], $handler->payloads);
    }

    /** The row a contextual handler is given is the queue's own, created_at and all. */
    public function testAContextualHandlerIsGivenTheQueueRow(): void
    {
        $queue = $this->queue(JobsDatabase::fresh());
        $id = $queue->enqueue('order.confirmation_email', ['order_id' => 9]);

        $handler = new RecordsOrder();
        $runner = $this->runner($queue);
        $runner->register('order.confirmation_email', $handler);

        $runner->run(5);

        self::assertSame($id, (int)$handler->rows[0]['id']);
        self::assertSame($this->now, (int)$handler->rows[0]['created_at']);
    }

    private function queue(Connection $db): JobQueue
    {
        return new JobQueue($db, JobsDatabase::tables(), 300, null, fn (): int => $this->now);
    }

    /**
     * The runner takes the queue's clock, so the only thing that moves time is
     * the sleep — a pass can therefore end only by draining the queue or by
     * sleeping its budget away, never because the test itself took a while.
     */
    private function runner(JobQueue $queue): JobRunner
    {
        return new JobRunner($queue, 'test-worker', null, function (int $seconds): void {
            $this->slept[] = $seconds;
            $this->now += $seconds;
        });
    }
}

/** Never ready. */
final class AlwaysDefers implements ContextualJobHandler
{
    public function handle(array $payload, array $job = []): void
    {
        throw new DeferJob(5);
    }
}

/** Not ready the first time it is asked, ready the second — the real case. */
final class DefersOnce implements ContextualJobHandler
{
    private bool $asked = false;

    public function handle(array $payload, array $job = []): void
    {
        if (!$this->asked) {
            $this->asked = true;

            throw new DeferJob(5);
        }
    }
}

/** Wants the queue row, and remembers every one it was handed. */
final class RecordsOrder implements ContextualJobHandler
{
    /** @var list<string> */
    public array $types = [];

    /** @var list<array<string, mixed>> */
    public array $rows = [];

    public function handle(array $payload, array $job = []): void
    {
        $this->types[] = (string)($job['type'] ?? '');
        $this->rows[] = $job;
    }
}

/** The handler every add-on in the wild is: one parameter, no idea about rows. */
final class PlainHandler implements JobHandler
{
    /** @var list<array<string, mixed>> */
    public array $payloads = [];

    public function handle(array $payload): void
    {
        $this->payloads[] = $payload;
    }
}
