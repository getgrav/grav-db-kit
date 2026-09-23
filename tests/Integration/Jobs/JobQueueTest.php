<?php

declare(strict_types=1);

namespace TrilbyMedia\GravDbKit\Tests\Integration\Jobs;

use PHPUnit\Framework\TestCase;
use TrilbyMedia\GravDbKit\Database\Connection;
use TrilbyMedia\GravDbKit\Database\KitTables;
use TrilbyMedia\GravDbKit\Jobs\JobQueue;
use TrilbyMedia\GravDbKit\Jobs\JobTrigger;
use TrilbyMedia\GravDbKit\Schema\InfraTables;
use TrilbyMedia\GravDbKit\Testing\FrozenClock;
use TrilbyMedia\GravDbKit\Tests\Jobs\Support\JobsDatabase;

/**
 * The queue itself: claim, complete, fail and backoff, stale takeover,
 * ordering, deferral, dedupe keys, the payload stamp, and the health read an
 * admin screen is built on. Ported from KahunaCart's JobQueueTest, with the
 * site-URL stamp now a payload stamp any plugin can supply.
 */
final class JobQueueTest extends TestCase
{
    private Connection $db;
    private FrozenClock $clock;
    private string $t;

    protected function setUp(): void
    {
        $this->db = JobsDatabase::fresh();
        $this->clock = new FrozenClock(1_700_000_000);
        $this->t = JobsDatabase::table();
    }

    public function testEnqueueClaimComplete(): void
    {
        $queue = $this->queue();

        $id = $queue->enqueue('order.email', ['order_id' => 42]);

        $job = $queue->claim('worker-1');
        $this->assertNotNull($job);
        $this->assertSame($id, (int)$job['id']);
        $this->assertSame('order.email', $job['type']);
        $this->assertSame(['order_id' => 42], json_decode((string)$job['payload_json'], true));
        $this->assertSame('worker-1', $job['locked_by']);
        $this->assertSame(1, (int)$job['attempts']);

        // Claimed job is invisible to other workers.
        $this->assertNull($queue->claim('worker-2'));

        $queue->complete($id);
        $this->assertNull($queue->claim('worker-1'));
        $this->assertSame(1, $queue->stats()['completed']);
    }

    public function testFailedJobRetriesWithBackoffThenExhausts(): void
    {
        $queue = $this->queue();

        $id = $queue->enqueue('webhook.deliver', [], 0, 2);

        $job = $queue->claim('w');
        $this->assertNotNull($job);
        $queue->fail($id, 'connection refused');

        // Backoff pushes run_after into the future — not immediately claimable.
        $this->assertNull($queue->claim('w'));

        // Make it runnable again and burn the final attempt.
        $this->db->update($this->t, ['run_after' => 0], 'id = ?', [$id]);
        $job = $queue->claim('w');
        $this->assertNotNull($job);
        $queue->fail($id, 'connection refused');
        $this->db->update($this->t, ['run_after' => 0], 'id = ?', [$id]);

        // Attempts exhausted: never claimable again, counted as failed.
        $this->assertNull($queue->claim('w'));
        $this->assertSame(1, $queue->stats()['failed']);
        $this->assertSame('connection refused', $queue->find($id)['last_error'] ?? null);
    }

    /** `min(3600, 2^attempts * 30)`: a minute, two, four … never more than an hour. */
    public function testTheBackoffDoublesAndIsCappedAtAnHour(): void
    {
        $queue = $this->queue();
        $id = $queue->enqueue('webhook.deliver', [], 0, 10);

        $waits = [];
        for ($attempt = 1; $attempt <= 8; $attempt++) {
            self::assertNotNull($queue->claim('w'));
            $queue->fail($id, 'nope');
            $waits[] = (int)$queue->find($id)['run_after'] - $this->clock->now();
            $this->clock->set((int)$queue->find($id)['run_after']);
        }

        self::assertSame([60, 120, 240, 480, 960, 1920, 3600, 3600], $waits);
        self::assertSame(60, JobQueue::backoff(1));
        self::assertSame(3600, JobQueue::backoff(64), 'no overflow on an absurd attempt count');
    }

    public function testFutureJobIsNotClaimable(): void
    {
        $queue = $this->queue();
        $queue->enqueue('cart.abandoned', [], $this->clock->now() + 3600);

        $this->assertNull($queue->claim('w'));
        $this->assertSame(0, $queue->stats()['pending']);
    }

    // ------------------------------------------------------- stale takeover

    /**
     * A worker that died mid-job leaves its lock behind. Once the lock is
     * older than the stale window the job is anybody's again, and the takeover
     * spends an attempt like any claim.
     */
    public function testAStaleLockIsTakenOverByTheNextWorker(): void
    {
        $queue = $this->queue();
        $id = $queue->enqueue('order.email');
        self::assertNotNull($queue->claim('dead-worker'));

        $this->clock->advance(299);
        self::assertNull($queue->claim('w2'), 'a lock inside the window is a worker still busy');
        self::assertSame(JobQueue::STATE_RUNNING, $queue->stateOf($queue->find($id) ?? []));

        $this->clock->advance(2);
        self::assertSame(JobQueue::STATE_PENDING, $queue->stateOf($queue->find($id) ?? []));

        $job = $queue->claim('w2');
        self::assertNotNull($job, 'past the window the job is taken over');
        self::assertSame($id, (int)$job['id']);
        self::assertSame('w2', $job['locked_by']);
        self::assertSame(2, (int)$job['attempts'], 'the takeover spent the second attempt');
        self::assertSame($this->clock->now(), (int)$job['locked_at']);
    }

    /** A job that kills its worker every time is not taken over for ever. */
    public function testAJobThatKeepsKillingItsWorkerRunsOutOfAttempts(): void
    {
        $queue = $this->queue();
        $id = $queue->enqueue('order.email');

        for ($i = 0; $i < 3; $i++) {
            self::assertNotNull($queue->claim('worker-' . $i));
            $this->clock->advance(301);
        }

        self::assertNull($queue->claim('worker-4'));
        self::assertSame(JobQueue::STATE_FAILED, $queue->stateOf($queue->find($id) ?? []));
    }

    /** The window is the queue's to set. */
    public function testTheStaleWindowIsConfigurable(): void
    {
        $queue = new JobQueue($this->db, JobsDatabase::tables(), 30, null, $this->clock);
        $queue->enqueue('order.email');
        $queue->claim('dead-worker');

        $this->clock->advance(31);

        self::assertNotNull($queue->claim('w2'));
        self::assertSame(30, $queue->staleLockSeconds());
    }

    /**
     * Two connections, one queue: the conditional UPDATE is what makes each
     * job one worker's, and it holds across separate sessions on every engine.
     */
    public function testTwoConnectionsNeverClaimTheSameJob(): void
    {
        $queue = $this->queue();
        for ($i = 1; $i <= 6; $i++) {
            $queue->enqueue('order.email', ['n' => $i]);
        }

        $other = new JobQueue(JobsDatabase::sibling(), JobsDatabase::tables(), 300, null, $this->clock);

        $seen = [];
        $turn = 0;
        while (true) {
            $job = ($turn++ % 2 === 0 ? $queue : $other)->claim('w' . $turn % 2);
            if ($job === null) {
                break;
            }
            $seen[] = (int)$job['id'];
        }

        self::assertCount(6, $seen);
        self::assertSame($seen, array_values(array_unique($seen)), 'no job claimed twice');
        self::assertSame(6, (int)$this->db->fetchValue("SELECT SUM(attempts) FROM {$this->t}"));
    }

    // ------------------------------------------------------- scheduler health

    public function testAnEmptyQueueIsNeverStale(): void
    {
        $health = $this->queue()->health(900);

        $this->assertSame(0, $health['pending']);
        $this->assertNull($health['oldest_pending_age']);
        $this->assertFalse($health['stale']);
    }

    public function testAQueueDrainedOnScheduleIsNotStale(): void
    {
        $queue = $this->queue();
        $queue->enqueue('order.confirmation_email', ['order_id' => 1]);

        $health = $queue->health(900);
        $this->assertSame(1, $health['pending']);
        $this->assertSame(0, $health['oldest_pending_age']);
        $this->assertFalse($health['stale']);
    }

    /**
     * The demo-site symptom: the scheduler was never wired into cron, so jobs
     * sat in the queue for hours while the site looked perfectly healthy.
     */
    public function testJobsWaitingLongerThanTheThresholdReportStale(): void
    {
        $queue = $this->queue();

        $now = $this->clock->now();
        foreach ([7200, 3600, 60] as $age) {
            $id = $queue->enqueue('order.grant_downloads', []);
            $this->db->update($this->t, ['created_at' => $now - $age], 'id = ?', [$id]);
        }

        $health = $queue->health(900, $now);

        $this->assertSame(3, $health['pending']);
        $this->assertSame(7200, $health['oldest_pending_age'], 'measured from the oldest job');
        $this->assertTrue($health['stale']);
        $this->assertSame(900, $health['stale_after']);
    }

    /**
     * A job's run_after moves forward with every retry backoff, so the age has
     * to be measured from created_at — otherwise a job that has been failing
     * for a day reports as thirty seconds behind.
     */
    public function testTheAgeIsMeasuredFromWhenTheWorkWasAskedForNotFromTheBackoff(): void
    {
        $queue = $this->queue();

        $now = $this->clock->now();
        $id = $queue->enqueue('order.confirmation_email', []);
        $this->db->update($this->t, ['created_at' => $now - 86400, 'run_after' => $now - 30], 'id = ?', [$id]);

        $this->assertSame(86400, $queue->oldestPendingAge($now));
    }

    public function testAJobStillWaitingForItsBackoffIsNotCountedYet(): void
    {
        $queue = $this->queue();

        $now = $this->clock->now();
        $id = $queue->enqueue('order.confirmation_email', []);
        $this->db->update($this->t, ['created_at' => $now - 86400, 'run_after' => $now + 60], 'id = ?', [$id]);

        $health = $queue->health(900, $now);
        $this->assertSame(0, $health['pending']);
        $this->assertNull($health['oldest_pending_age']);
        $this->assertFalse($health['stale'], 'the worker may be running fine; this one is backing off');
    }

    /**
     * A job that exhausted its attempts is a different problem — the worker ran
     * and the job kept throwing — so it is counted separately and does not by
     * itself make the queue look stale.
     */
    public function testExhaustedJobsAreCountedAsFailedRatherThanStale(): void
    {
        $queue = $this->queue();

        $now = $this->clock->now();
        $id = $queue->enqueue('order.confirmation_email', [], 0, 1);
        $this->db->update(
            $this->t,
            ['attempts' => 1, 'created_at' => $now - 86400, 'last_error' => 'boom'],
            'id = ?',
            [$id]
        );

        $health = $queue->health(900, $now);
        $this->assertSame(1, $health['failed']);
        $this->assertSame(0, $health['pending']);
        $this->assertFalse($health['stale']);
    }

    /** Cancelled jobs are none of pending, failed or completed. */
    public function testStatsLeaveCancelledJobsOut(): void
    {
        $queue = $this->queue();
        $queue->cancel($queue->enqueue('a'));

        self::assertSame(['pending' => 0, 'failed' => 0, 'completed' => 0], $queue->stats());
    }

    // --------------------------------------------------------- payload stamp

    /**
     * What KahunaCart's site-URL stamp became: a callable handed every payload
     * at enqueue time. The worker has no request; the request that queued the
     * work did, and the stamp is how it leaves a note.
     *
     * @return array<string, mixed>
     */
    private function payloadOf(JobQueue $queue, int $id): array
    {
        $job = $queue->claim('w');
        self::assertNotNull($job);
        self::assertSame($id, (int)$job['id']);

        return json_decode((string)$job['payload_json'], true, 512, JSON_THROW_ON_ERROR);
    }

    /**
     * KahunaCart's rule written as a stamp: add the URL when there is one to
     * add, and never over a caller's own value.
     */
    private static function siteUrlStamp(string $url): \Closure
    {
        return static function (array $payload) use ($url): array {
            if (!isset($payload['_site_url']) && $url !== '') {
                $payload['_site_url'] = $url;
            }

            return $payload;
        };
    }

    public function testThePayloadStampIsAppliedAtEnqueueTime(): void
    {
        $queue = new JobQueue($this->db, JobsDatabase::tables(), 300, self::siteUrlStamp('https://shop.example.com'), $this->clock);

        $id = $queue->enqueue('order.confirmation_email', ['order_id' => 7]);

        self::assertSame(
            ['order_id' => 7, '_site_url' => 'https://shop.example.com'],
            $this->payloadOf($queue, $id)
        );
    }

    public function testAStampWithNothingToAddLeavesThePayloadAlone(): void
    {
        // The CLI case. An empty string in the payload would look like an
        // answer; leaving the key out lets the handler ask the config instead.
        $queue = new JobQueue($this->db, JobsDatabase::tables(), 300, self::siteUrlStamp(''), $this->clock);

        $id = $queue->enqueue('order.confirmation_email', ['order_id' => 7]);

        self::assertSame(['order_id' => 7], $this->payloadOf($queue, $id));
    }

    public function testACallersOwnValueIsNeverOverwritten(): void
    {
        $queue = new JobQueue($this->db, JobsDatabase::tables(), 300, self::siteUrlStamp('https://admin.example.com'), $this->clock);

        $id = $queue->enqueue('order.confirmation_email', [
            'order_id' => 7,
            '_site_url' => 'https://storefront.example.com',
        ]);

        self::assertSame('https://storefront.example.com', $this->payloadOf($queue, $id)['_site_url']);
    }

    public function testAnEmptyPayloadIsStampedToo(): void
    {
        $queue = new JobQueue($this->db, JobsDatabase::tables(), 300, self::siteUrlStamp('https://shop.example.com'), $this->clock);

        $id = $queue->enqueue('cart.abandoned_sweep');

        self::assertSame(['_site_url' => 'https://shop.example.com'], $this->payloadOf($queue, $id));
    }

    /** A queue with no stamp stores exactly what it was given. */
    public function testNoStampMeansThePayloadAsGiven(): void
    {
        $queue = $this->queue();

        self::assertSame(['a' => 1], $this->payloadOf($queue, $queue->enqueue('x', ['a' => 1])));
    }

    /** A stamp that throws stops the enqueue before anything is written. */
    public function testAStampThatThrowsWritesNothing(): void
    {
        $queue = new JobQueue($this->db, JobsDatabase::tables(), 300, static function (): array {
            throw new \RuntimeException('no request to read');
        }, $this->clock);

        try {
            $queue->enqueue('x');
            self::fail('the stamp\'s exception should reach the caller');
        } catch (\RuntimeException $e) {
            self::assertSame('no request to read', $e->getMessage());
        }

        self::assertSame(0, (int)$this->db->fetchValue("SELECT COUNT(*) FROM {$this->t}"));
    }

    // ------------------------------------------------------------- ordering

    /**
     * The queue is first-in-first-out by id, and callers rely on it: licence
     * keys are minted by one job and the email carrying them is queued straight
     * after, so the email's higher id is what guarantees the keys exist before
     * it renders.
     */
    public function testRunnableJobsAreClaimedInTheOrderTheyWereQueued(): void
    {
        $queue = $this->queue();

        $queue->enqueue('licenses.issue', ['order_id' => 1]);
        $queue->enqueue('order.confirmation_email', ['order_id' => 1]);
        $queue->enqueue('order.grant_downloads', ['order_id' => 1]);

        $claimed = [];
        while (($job = $queue->claim('w')) !== null) {
            $claimed[] = (string)$job['type'];
            $queue->complete((int)$job['id']);
        }

        self::assertSame(['licenses.issue', 'order.confirmation_email', 'order.grant_downloads'], $claimed);
    }

    // ------------------------------------------------------------- deferral

    /**
     * A deferral is not a failure. The job comes back claimable at the time the
     * caller named, with no error written and — the part that matters — the
     * attempt claim() consumed handed back, so an email that waited ten times
     * for another job still has all three real attempts left.
     */
    public function testDeferringAJobKeepsItsAttemptsAndMovesItsRunAfter(): void
    {
        $queue = $this->queue();
        $id = $queue->enqueue('order.confirmation_email', ['order_id' => 7]);

        $queue->claim('w');
        self::assertSame(1, (int)$this->db->fetchValue("SELECT attempts FROM {$this->t} WHERE id = ?", [$id]));

        $due = $this->clock->now() + 5;
        $queue->defer($id, $due);

        $row = $queue->find($id);
        self::assertNotNull($row);
        self::assertSame(0, (int)$row['attempts'], 'waiting must not cost the job an attempt');
        self::assertSame($due, (int)$row['run_after']);
        self::assertNull($row['locked_at']);
        self::assertNull($row['last_error'], 'nothing went wrong, so nothing is reported');
    }

    public function testADeferredJobIsClaimableAgainOnceItIsDue(): void
    {
        $queue = $this->queue();
        $id = $queue->enqueue('order.confirmation_email');

        $queue->claim('w');
        $queue->defer($id, $this->clock->now() + 5);
        self::assertNull($queue->claim('w'), 'still holding');

        $this->clock->advance(5);
        $job = $queue->claim('w');

        self::assertNotNull($job);
        self::assertSame(1, (int)$job['attempts'], 'the first real attempt is only now being spent');
    }

    public function testDeferringAJobThatIsNotThereIsANoOp(): void
    {
        $queue = $this->queue();

        $queue->defer(4242, $this->clock->now() + 5);

        self::assertSame(0, $queue->stats()['pending']);
    }

    // --------------------------------------------------------- next due job

    /**
     * What the worker asks after claim() comes back empty, to tell a drained
     * queue apart from one holding something for another two seconds.
     */
    public function testNextRunAfterReportsTheSoonestJobThatIsNotDueYet(): void
    {
        $queue = $this->queue();
        $now = $this->clock->now();

        $queue->enqueue('cart.abandoned_sweep', [], $now + 60);
        $queue->enqueue('order.confirmation_email', [], $now + 5);

        self::assertSame($now + 5, $queue->nextRunAfter($now));
    }

    public function testNextRunAfterIsNullWhenNothingIsWaiting(): void
    {
        self::assertNull($this->queue()->nextRunAfter());
    }

    /**
     * A job that is due now and still was not claimed belongs to another
     * worker; waiting for it would be waiting for nothing.
     */
    public function testNextRunAfterIgnoresJobsThatAreAlreadyDue(): void
    {
        $queue = $this->queue();
        $queue->enqueue('order.confirmation_email');

        self::assertNull($queue->nextRunAfter());
    }

    public function testNextRunAfterIgnoresExhaustedJobs(): void
    {
        $queue = $this->queue();
        $id = $queue->enqueue('webhook.deliver', [], 0, 1);

        $queue->claim('w');
        $queue->fail($id, 'nope');

        self::assertNull($queue->nextRunAfter(), 'it will never run again, so it is not worth waiting for');
    }

    // ------------------------------------------------------------ dedupe key

    /**
     * What the key is for: recurring work that is booked from two places —
     * the sweep re-booking itself for the next interval, and whatever noticed
     * there was something to sweep — and would otherwise become two sweeps
     * and then four.
     */
    public function testADedupeKeyAnswersTheWaitingJobRatherThanQueueingASecond(): void
    {
        $queue = $this->queue();

        $first = $queue->enqueue('shipping.poll_tracking', [], 0, 3, 'shipping.poll_tracking');
        $second = $queue->enqueue('shipping.poll_tracking', [], 0, 3, 'shipping.poll_tracking');

        self::assertGreaterThan(0, $first);
        self::assertSame($first, $second, 'the second call books nothing and names the job already there');
        self::assertSame(1, $queue->stats()['pending']);
        self::assertSame($first, $queue->unrunJobFor('shipping.poll_tracking'));
    }

    /** And the key is free again the moment its job is done. */
    public function testADedupeKeyIsFreeAgainOnceItsJobCompleted(): void
    {
        $queue = $this->queue();

        $first = $queue->enqueue('shipping.poll_tracking', [], 0, 3, 'shipping.poll_tracking');
        $queue->claim('w');
        $queue->complete($first);

        self::assertNull($queue->unrunJobFor('shipping.poll_tracking'));

        $second = $queue->enqueue('shipping.poll_tracking', [], 0, 3, 'shipping.poll_tracking');

        self::assertNotSame($first, $second, 'a finished sweep must not keep the next one switched off');
        self::assertCount(2, $queue->page(type: 'shipping.poll_tracking')['jobs']);
    }

    /** A running job still holds its key: it has not finished yet. */
    public function testARunningJobStillHoldsItsKey(): void
    {
        $queue = $this->queue();

        $first = $queue->enqueue('digest.sweep', [], 0, 3, 'digest.sweep');
        $queue->claim('w');

        self::assertSame($first, $queue->enqueue('digest.sweep', [], 0, 3, 'digest.sweep'));
    }

    /**
     * A cancelled job does not hold the key either, and neither does one that
     * spent its last attempt: a sweep that failed three times must not go
     * silent for ever, which is the worst way for one to stop.
     */
    public function testAJobThatWillNeverRunDoesNotHoldItsKey(): void
    {
        $queue = $this->queue();

        $cancelled = $queue->enqueue('shipping.poll_tracking', [], 0, 3, 'cancelled-one');
        $queue->cancel($cancelled);
        self::assertNull($queue->unrunJobFor('cancelled-one'));

        $exhausted = $queue->enqueue('shipping.poll_tracking', [], 0, 1, 'failed-one');
        $queue->claim('w');
        $queue->fail($exhausted, 'the carrier never answered');

        self::assertNull($queue->unrunJobFor('failed-one'));
        self::assertNotSame($exhausted, $queue->enqueue('shipping.poll_tracking', [], 0, 1, 'failed-one'));
    }

    /**
     * Everything without a key behaves exactly as it always has — two calls,
     * two jobs — which is right for a message: two shipments are two emails.
     */
    public function testAJobWithNoKeyIsQueuedEveryTimeItIsAskedFor(): void
    {
        $queue = $this->queue();

        $first = $queue->enqueue('order.shipped_email', ['order_id' => 1]);
        $second = $queue->enqueue('order.shipped_email', ['order_id' => 1]);

        self::assertNotSame($first, $second);
        self::assertSame(2, $queue->stats()['pending']);
        self::assertNull($queue->unrunJobFor(''), 'an empty key is no key at all');
        self::assertNotSame($first, $queue->enqueue('order.shipped_email', [], 0, 3, '   '), 'nor is a blank one');
    }

    /** Two keys are two jobs, however alike the work is. */
    public function testTwoKeysAreTwoJobs(): void
    {
        $queue = $this->queue();

        $a = $queue->enqueue('shipping.poll_tracking', [], 0, 3, 'store-1');
        $b = $queue->enqueue('shipping.poll_tracking', [], 0, 3, 'store-2');

        self::assertNotSame($a, $b);
        self::assertSame(2, $queue->stats()['pending']);
    }

    // --------------------------------------------------------- bring forward

    public function testADedupedJobBookedForLaterCanBeBroughtForward(): void
    {
        $queue = $this->queue();
        $now = $this->clock->now();

        $booked = $queue->enqueue('feeds.rebuild', [], $now + 300, 3, 'feeds.rebuild');
        self::assertSame($booked, $queue->enqueue('feeds.rebuild', [], $now, 3, 'feeds.rebuild'), 'still one job');
        self::assertSame($now + 300, (int)$queue->find($booked)['run_after'], 'enqueueing again moves nothing');

        self::assertTrue($queue->bringForward($booked, $now + 10));
        self::assertSame($now + 10, (int)$queue->find($booked)['run_after']);

        self::assertFalse($queue->bringForward($booked, $now + 20), 'a later time never pushes a job back');
        self::assertSame($now + 10, (int)$queue->find($booked)['run_after']);
    }

    public function testAJobAWorkerHoldsIsNotBroughtForward(): void
    {
        $queue = $this->queue();
        $now = $this->clock->now();
        $id = $queue->enqueue('feeds.rebuild', [], $now + 300);
        $this->db->update($this->t, ['locked_at' => $now, 'locked_by' => 'w1'], 'id = ?', [$id]);

        self::assertFalse($queue->bringForward($id, $now));
        self::assertSame($now + 300, (int)$queue->find($id)['run_after']);
    }

    // ---------------------------------------------------- results and secrets

    /** One job for a whole batch, and the report written back onto it. */
    public function testAJobCarriesItsOwnReport(): void
    {
        $queue = $this->queue();
        $id = $queue->enqueue('orders.bulk_status', ['order_ids' => [1, 2, 3], 'status' => 'archived']);

        self::assertNull($queue->resultFor($id));

        $queue->attachResult($id, ['changed' => 2, 'failed' => [3 => 'locked']]);

        self::assertSame(['changed' => 2, 'failed' => [3 => 'locked']], $queue->resultFor($id));
        $payload = json_decode((string)$queue->find($id)['payload_json'], true);
        self::assertSame('archived', $payload['status'], 'the payload the caller queued is still there beside the report');
        self::assertSame([1, 2, 3], $payload['order_ids']);
    }

    public function testAttachingAResultToAJobThatIsGoneIsANoOp(): void
    {
        $queue = $this->queue();

        $queue->attachResult(9999, ['changed' => 1]);

        self::assertNull($queue->resultFor(9999));
        self::assertNull($queue->find(9999));
    }

    /** A redacted payload keeps everything but the secret. */
    public function testRedactingAJobPayloadRemovesOnlyTheNamedKeys(): void
    {
        $queue = $this->queue();
        $id = $queue->enqueue('order.gift_card_email', ['order_id' => 7, 'gift_card_id' => 3, 'code' => 'ABCD-EFGH']);

        $queue->redact($id, ['code']);
        $queue->redact($id, ['never-there']);
        $queue->redact(9999, ['code']);

        self::assertSame(
            ['order_id' => 7, 'gift_card_id' => 3],
            json_decode((string)$queue->find($id)['payload_json'], true)
        );
    }

    // --------------------------------------------------- tables and triggers

    /** The queue writes to the table KitTables names, and nowhere else. */
    public function testTheTableNameComesFromKitTables(): void
    {
        $tables = new KitTables(jobs: 'kitjobs_custom_queue');
        InfraTables::jobs($this->db->dialect(), $tables)($this->db);

        $queue = new JobQueue($this->db, $tables, 300, null, $this->clock);
        $id = $queue->enqueue('x');

        self::assertSame('kitjobs_custom_queue', $queue->table());
        self::assertSame($id, (int)$this->db->fetchValue('SELECT id FROM kitjobs_custom_queue'));
        self::assertSame(0, (int)$this->db->fetchValue("SELECT COUNT(*) FROM {$this->t}"));
    }

    /** Every enqueue tells the trigger; a deduped one that wrote nothing does not. */
    public function testTheTriggerIsWokenForEachNewJob(): void
    {
        $trigger = new class implements JobTrigger {
            /** @var list<int> */
            public array $woken = [];

            public function wake(int $jobId): void
            {
                $this->woken[] = $jobId;
            }
        };
        $queue = new JobQueue($this->db, JobsDatabase::tables(), 300, null, $this->clock, $trigger);

        $a = $queue->enqueue('a');
        $b = $queue->enqueue('b', [], 0, 3, 'key');
        $queue->enqueue('b', [], 0, 3, 'key');

        self::assertSame([$a, $b], $trigger->woken);
    }

    /** A trigger that throws gives up the latency it was there to save and nothing else. */
    public function testATriggerThatThrowsCannotLoseTheJob(): void
    {
        $trigger = new class implements JobTrigger {
            public function wake(int $jobId): void
            {
                throw new \RuntimeException('the broker went away');
            }
        };
        $queue = new JobQueue($this->db, JobsDatabase::tables(), 300, null, $this->clock, $trigger);

        $id = $queue->enqueue('order.confirmation_email');

        self::assertSame($id, (int)($queue->claim('w')['id'] ?? 0));
    }

    /** A Clock and a plain callable are the same thing to the queue. */
    public function testTheClockMayBeAClockOrACallable(): void
    {
        self::assertSame(1_700_000_000, $this->queue()->now());
        self::assertSame(42, (new JobQueue($this->db, JobsDatabase::tables(), 300, null, static fn (): int => 42))->now());
        self::assertEqualsWithDelta(time(), (new JobQueue($this->db, JobsDatabase::tables()))->now(), 2);
    }

    private function queue(): JobQueue
    {
        return new JobQueue($this->db, JobsDatabase::tables(), 300, null, $this->clock);
    }
}
