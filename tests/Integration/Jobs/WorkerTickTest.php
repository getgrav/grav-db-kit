<?php

declare(strict_types=1);

namespace TrilbyMedia\GravDbKit\Tests\Integration\Jobs;

use PHPUnit\Framework\TestCase;
use TrilbyMedia\GravDbKit\Jobs\JobQueue;
use TrilbyMedia\GravDbKit\Jobs\WorkerTick;
use TrilbyMedia\GravDbKit\Testing\FrozenClock;
use TrilbyMedia\GravDbKit\Tests\Jobs\Support\JobsDatabase;

/**
 * The one moment in a worker run recurring work can hang on.
 *
 * Three properties, and the third is the one the whole thing exists for. The
 * tick happens once per run and carries the queue, so a listener has something
 * to enqueue on. A listener that throws costs its own work and nothing else,
 * because the worker may still be holding somebody's confirmation email. And
 * a listener that books a sweep with a dedupe key gets one sweep however many
 * times the worker runs before it.
 *
 * KahunaCart fired this as a Grav event; the kit calls plain callbacks.
 */
final class WorkerTickTest extends TestCase
{
    public function testTheTickCallsEachListenerOnceWithTheQueueAndTheTime(): void
    {
        $queue = $this->queue();
        $seen = [];

        $tick = new WorkerTick([static function (JobQueue $q, int $now) use (&$seen): void {
            $seen[] = [$q, $now];
        }]);
        $result = $tick->fire($queue, 1_700_000_000);

        self::assertCount(1, $seen, 'one run, one tick');
        self::assertSame($queue, $seen[0][0]);
        self::assertSame(1_700_000_000, $seen[0][1]);
        self::assertSame(['now' => 1_700_000_000, 'listeners' => 1, 'failed' => 0], $result);
    }

    /** With no time given it uses the queue's own clock, which a test can move. */
    public function testTheTickTakesItsTimeFromTheQueueWhenNobodySaysOtherwise(): void
    {
        $queue = new JobQueue(JobsDatabase::fresh(), JobsDatabase::tables(), 300, null, new FrozenClock(1_234_567));

        self::assertSame(1_234_567, (new WorkerTick())->fire($queue)['now']);
    }

    /**
     * A listener that throws must not reach the rest of the tick — nor, now
     * that listeners are called one at a time, the listeners after it.
     */
    public function testAListenerThatThrowsIsSwallowedAndReportedAndTheNextStillRuns(): void
    {
        $queue = $this->queue();
        $logged = [];
        $after = 0;

        $tick = new WorkerTick([], static function (string $message) use (&$logged): void {
            $logged[] = $message;
        });
        $tick->listen(static function (): void {
            throw new \RuntimeException('the aggregator went away');
        });
        $tick->listen(static function () use (&$after): void {
            $after++;
        });

        $result = $tick->fire($queue, 1_700_000_000);

        self::assertSame(1_700_000_000, $result['now']);
        self::assertSame(1, $result['failed']);
        self::assertSame(1, $after, 'the listener after the broken one still ran');
        self::assertCount(1, $logged);
        self::assertStringContainsString('the aggregator went away', $logged[0]);
        self::assertStringContainsString('worker tick', $logged[0]);
    }

    /** A logger that throws as well is not a reason for the tick to. */
    public function testALoggerThatThrowsIsSwallowedToo(): void
    {
        $tick = new WorkerTick(
            [static function (): void {
                throw new \RuntimeException('listener');
            }],
            static function (): void {
                throw new \RuntimeException('logger');
            }
        );

        self::assertSame(1, $tick->fire($this->queue(), 1)['failed']);
    }

    /**
     * What an add-on actually writes: one line in a listener, and two ticks
     * before the sweep has run book one sweep rather than two.
     */
    public function testAListenerBooksRecurringWorkOnceHoweverOftenTheWorkerRuns(): void
    {
        $queue = $this->queue();

        $tick = (new WorkerTick())->listen(static function (JobQueue $queue): void {
            $queue->enqueue('shipping.poll_tracking', [], 0, 3, 'shipping.poll_tracking');
        });

        $tick->fire($queue, 1_700_000_000);
        $tick->fire($queue, 1_700_000_060);

        self::assertCount(1, $queue->page(type: 'shipping.poll_tracking')['jobs'], 'one sweep, not two');

        // And once it has run, the next tick books the next one.
        $id = (int)$queue->page(type: 'shipping.poll_tracking')['jobs'][0]['id'];
        $queue->claim('w');
        $queue->complete($id);

        $tick->fire($queue, 1_700_000_120);

        self::assertCount(2, $queue->page(type: 'shipping.poll_tracking')['jobs']);
        self::assertSame(1, $tick->count());
    }

    private function queue(): JobQueue
    {
        return new JobQueue(JobsDatabase::fresh(), JobsDatabase::tables(), 300, null, new FrozenClock());
    }
}
