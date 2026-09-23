<?php

declare(strict_types=1);

namespace TrilbyMedia\GravDbKit\Tests\Unit\Jobs;

use PHPUnit\Framework\TestCase;
use TrilbyMedia\GravDbKit\Jobs\DeferJob;
use TrilbyMedia\GravDbKit\Jobs\JobTimedOut;
use TrilbyMedia\GravDbKit\Jobs\PendingDrain;

/**
 * The list a request keeps of the jobs it queued, and the small value types
 * the runner reads: a deferral's delay and a timeout's message.
 */
final class PendingDrainTest extends TestCase
{
    public function testIdsAreKeptInTheOrderTheyWereQueued(): void
    {
        $pending = new PendingDrain();
        $pending->wake(3);
        $pending->wake(1);
        $pending->wake(2);

        self::assertSame([3, 1, 2], $pending->ids());
        self::assertFalse($pending->isEmpty());
    }

    /** A request cannot make itself an unbounded worker by queueing enough. */
    public function testTheListOfIdsIsCapped(): void
    {
        $pending = new PendingDrain();
        for ($i = 1; $i <= PendingDrain::MAX_IDS + 10; $i++) {
            $pending->wake($i);
        }

        self::assertCount(PendingDrain::MAX_IDS, $pending->ids());
        self::assertTrue($pending->overflowed());

        $pending->clear();
        self::assertTrue($pending->isEmpty());
        self::assertFalse($pending->overflowed());
    }

    /**
     * The list is handed over once. Whichever shutdown hook reaches it first
     * gets it; every later one gets null, and nothing queued after the
     * hand-over is collected for them.
     */
    public function testTheListIsTakenOnceAndThenCollectsNothing(): void
    {
        $pending = new PendingDrain();
        $pending->wake(1);
        $pending->wake(2);

        self::assertSame(['ids' => [1, 2], 'overflowed' => false], $pending->take());
        self::assertTrue($pending->drained());
        self::assertTrue($pending->isEmpty());

        $pending->wake(3);

        self::assertSame([], $pending->ids(), 'work queued after the drain belongs to the worker');
        self::assertNull($pending->take());
    }

    public function testAnEmptyListIsStillTakenOnce(): void
    {
        $pending = new PendingDrain();

        self::assertSame(['ids' => [], 'overflowed' => false], $pending->take());
        self::assertNull($pending->take());
    }

    /** A deferral to "now" would spin, so the floor is one second. */
    public function testADeferralIsNeverShorterThanOneSecond(): void
    {
        self::assertSame(1, (new DeferJob(0))->seconds);
        self::assertSame(1, (new DeferJob(-5))->seconds);
        self::assertSame(DeferJob::DEFAULT_SECONDS, (new DeferJob())->seconds);
        self::assertSame('job deferred for 7s', (new DeferJob(7))->getMessage());
        self::assertSame('waiting on the panels', (new DeferJob(7, 'waiting on the panels'))->getMessage());
    }

    public function testATimeoutSaysHowLongItRanWhenThatIsKnown(): void
    {
        self::assertStringContainsString('(it ran for 45s)', (new JobTimedOut(10, 45))->getMessage());
        self::assertStringNotContainsString('it ran for', (new JobTimedOut(10))->getMessage());
        self::assertStringNotContainsString('jobs.job_timeout_seconds', (new JobTimedOut(10))->getMessage(), 'no plugin\'s setting name in the kit');
    }
}
