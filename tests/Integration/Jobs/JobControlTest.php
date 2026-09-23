<?php

declare(strict_types=1);

namespace TrilbyMedia\GravDbKit\Tests\Integration\Jobs;

use PHPUnit\Framework\TestCase;
use TrilbyMedia\GravDbKit\Database\Connection;
use TrilbyMedia\GravDbKit\Jobs\ContextualJobHandler;
use TrilbyMedia\GravDbKit\Jobs\JobCancellation;
use TrilbyMedia\GravDbKit\Jobs\JobHandler;
use TrilbyMedia\GravDbKit\Jobs\JobQueue;
use TrilbyMedia\GravDbKit\Jobs\JobRunner;
use TrilbyMedia\GravDbKit\Testing\FrozenClock;
use TrilbyMedia\GravDbKit\Tests\Jobs\Support\JobsDatabase;

/**
 * An admin's Run and Cancel over the queue, and the one property that makes
 * Cancel safe to offer: a running job stops at a transaction boundary and
 * never in the middle of one.
 */
final class JobControlTest extends TestCase
{
    private Connection $db;
    private JobQueue $queue;
    private FrozenClock $clock;
    private string $t;

    protected function setUp(): void
    {
        $this->db = JobsDatabase::fresh();
        $this->clock = new FrozenClock(1_700_000_000);
        $this->queue = new JobQueue($this->db, JobsDatabase::tables(), 300, null, $this->clock);
        $this->t = JobsDatabase::table();
    }

    // ------------------------------------------------------------- states

    public function testEveryStateIsReadOffTheRowTheWayClaimReadsIt(): void
    {
        $now = $this->clock->now();
        $pending = $this->queue->enqueue('a', []);
        $complete = $this->queue->enqueue('b', []);
        $this->queue->complete($complete);
        $failed = $this->queue->enqueue('c', [], 0, 1);
        $this->queue->claimOne($failed, 'w');
        $this->queue->fail($failed, 'boom');
        $running = $this->queue->enqueue('d', []);
        $this->db->update($this->t, ['locked_at' => $now, 'locked_by' => 'w'], 'id = ?', [$running]);
        $stale = $this->queue->enqueue('e', []);
        $this->db->update($this->t, ['locked_at' => $now - 3600, 'locked_by' => 'dead'], 'id = ?', [$stale]);
        $cancelled = $this->queue->enqueue('f', []);
        $this->queue->cancel($cancelled);

        $state = fn (int $id): string => $this->queue->stateOf($this->queue->find($id) ?? [], $now);

        self::assertSame(JobQueue::STATE_PENDING, $state($pending));
        self::assertSame(JobQueue::STATE_COMPLETE, $state($complete));
        self::assertSame(JobQueue::STATE_FAILED, $state($failed));
        self::assertSame(JobQueue::STATE_RUNNING, $state($running));
        self::assertSame(JobQueue::STATE_PENDING, $state($stale), 'a dead worker\'s lock is a job waiting again');
        self::assertSame(JobQueue::STATE_CANCELLED, $state($cancelled));

        $counts = $this->queue->countByState($now);
        self::assertSame(2, $counts[JobQueue::STATE_PENDING]);
        self::assertSame(1, $counts[JobQueue::STATE_RUNNING]);
        self::assertSame(1, $counts[JobQueue::STATE_COMPLETE]);
        self::assertSame(1, $counts[JobQueue::STATE_FAILED]);
        self::assertSame(1, $counts[JobQueue::STATE_CANCELLED]);
    }

    public function testThePageFiltersByStateAndByType(): void
    {
        $this->queue->enqueue('order.confirmation_email', ['order_id' => 1]);
        $this->queue->enqueue('order.confirmation_email', ['order_id' => 2]);
        $done = $this->queue->enqueue('stock.release_expired', []);
        $this->queue->complete($done);

        $pending = $this->queue->page(JobQueue::STATE_PENDING);
        self::assertSame(2, $pending['total']);
        self::assertSame(2, (int)json_decode((string)$pending['jobs'][0]['payload_json'], true)['order_id'], 'newest first');

        $complete = $this->queue->page(JobQueue::STATE_COMPLETE);
        self::assertSame(1, $complete['total']);
        self::assertSame($done, (int)$complete['jobs'][0]['id']);

        $typed = $this->queue->page(null, 'stock.release_expired');
        self::assertSame(1, $typed['total']);

        $all = $this->queue->page(null, null, 2, 0);
        self::assertSame(3, $all['total']);
        self::assertCount(2, $all['jobs']);

        $second = $this->queue->page(null, null, 2, 2);
        self::assertCount(1, $second['jobs']);
        self::assertSame(3, $second['total']);
    }

    // ------------------------------------------------------------- cancel

    public function testCancellingAJobThatHasNotStartedStopsItOutright(): void
    {
        $id = $this->queue->enqueue('order.confirmation_email', ['order_id' => 1]);

        self::assertSame(JobQueue::CANCEL_DONE, $this->queue->cancel($id));
        self::assertNull($this->queue->claim('w'), 'a cancelled job is never claimed');
        self::assertSame(0, $this->queue->stats()['pending']);
        self::assertSame(JobQueue::CANCEL_TOO_LATE, $this->queue->cancel($id), 'cancelling twice is a no-op');
    }

    public function testCancellingARunningJobOnlyRecordsTheRequest(): void
    {
        $id = $this->queue->enqueue('orders.bulk_status', []);
        $this->queue->claim('w');

        self::assertSame(JobQueue::CANCEL_REQUESTED, $this->queue->cancel($id));
        self::assertTrue($this->queue->cancelRequested($id));

        $row = $this->queue->find($id);
        self::assertNotNull($row);
        self::assertNull($row['cancelled_at'], 'nothing is killed: the worker decides when to stop');
        self::assertNotNull($row['locked_at'], 'the lock is left with the worker that holds it');
    }

    /** A job whose worker died is not running, so Cancel stops it outright. */
    public function testCancellingAJobWithAStaleLockStopsItOutright(): void
    {
        $id = $this->queue->enqueue('a', []);
        $this->queue->claim('dead-worker');
        $this->clock->advance(301);

        self::assertSame(JobQueue::CANCEL_DONE, $this->queue->cancel($id));
        $row = $this->queue->find($id);
        self::assertNotNull($row);
        self::assertNull($row['locked_at'], 'the dead worker\'s lock is cleared');
        self::assertNull($this->queue->claim('w2'));
    }

    public function testCancellingAFinishedJobIsTooLate(): void
    {
        $id = $this->queue->enqueue('a', []);
        $this->queue->complete($id);

        self::assertSame(JobQueue::CANCEL_TOO_LATE, $this->queue->cancel($id));
        self::assertSame(JobQueue::CANCEL_UNKNOWN, $this->queue->cancel(999));
    }

    public function testARequestMadeWhileWaitingIsHonouredBeforeTheHandlerStarts(): void
    {
        $id = $this->queue->enqueue('a', []);
        // The race: the request lands between the pending check and the
        // claim, so the row carries a request but was claimed anyway.
        $this->db->update($this->t, ['cancel_requested_at' => $this->clock->now()], 'id = ?', [$id]);

        $ran = false;
        $runner = new JobRunner($this->queue, 'w');
        $runner->register('a', new class($ran) implements JobHandler {
            public function __construct(private bool &$ran)
            {
            }

            public function handle(array $payload): void
            {
                $this->ran = true;
            }
        });

        $runner->run(1);

        self::assertFalse($ran, 'the handler never started');
        self::assertSame(JobQueue::STATE_CANCELLED, $this->queue->stateOf($this->queue->find($id) ?? []));
    }

    /**
     * The acceptance test. A handler that writes five steps in five
     * transactions and asks between them is cancelled during the second, and
     * the table holds exactly two steps: nothing from a third transaction, and
     * nothing from half of one.
     */
    public function testCancellingARunningJobLeavesNoHalfWrittenRows(): void
    {
        $fixture = JobsDatabase::PREFIX . '_boundary_fixture';
        $this->db->run("CREATE TABLE {$fixture} (id {$this->db->dialect()->primaryKey()}, step INTEGER NOT NULL, half INTEGER NOT NULL)");
        $id = $this->queue->enqueue('boundary', ['steps' => 5]);

        $queue = $this->queue;
        $db = $this->db;
        $runner = new JobRunner($queue, 'w');
        $runner->register('boundary', new class($db, $queue, $fixture) implements ContextualJobHandler {
            public function __construct(
                private readonly Connection $db,
                private readonly JobQueue $queue,
                private readonly string $fixture,
            ) {
            }

            public function handle(array $payload, array $job = []): void
            {
                for ($step = 1; $step <= (int)$payload['steps']; $step++) {
                    // The boundary: after the previous transaction committed,
                    // before this one begins.
                    JobCancellation::checkpoint($this->queue, $job);

                    $this->db->transaction(function (Connection $db) use ($step, $job): void {
                        // Two writes per step, and the cancel lands between
                        // them — inside the transaction — which is exactly
                        // where it must not take effect.
                        $db->insert($this->fixture, ['step' => $step, 'half' => 1]);
                        if ($step === 2) {
                            $this->queue->cancel((int)$job['id']);
                        }
                        $db->insert($this->fixture, ['step' => $step, 'half' => 2]);
                    });
                }
            }
        });

        $result = $runner->run(5);

        $rows = $this->db->fetchAll("SELECT step, half FROM {$fixture} ORDER BY id");
        self::assertSame(
            [['step' => 1, 'half' => 1], ['step' => 1, 'half' => 2], ['step' => 2, 'half' => 1], ['step' => 2, 'half' => 2]],
            array_map(static fn (array $r): array => ['step' => (int)$r['step'], 'half' => (int)$r['half']], $rows),
            'two whole steps, no third, no half of anything'
        );

        $job = $this->queue->find($id);
        self::assertNotNull($job);
        self::assertSame(JobQueue::STATE_CANCELLED, $this->queue->stateOf($job));
        self::assertNull($job['completed_at']);
        self::assertNull($job['locked_at'], 'the lock was given back');
        self::assertSame(0, (int)$job['attempts'], 'obeying is not failing: the attempt is returned');
        self::assertNull($job['last_error']);
        self::assertSame(['processed' => 0, 'failed' => 0, 'deferred' => 0], $result);
    }

    /** A checkpoint with no row to read is a no-op, for a handler run outside the queue. */
    public function testACheckpointWithNoJobRowNeverStops(): void
    {
        JobCancellation::checkpoint($this->queue, []);

        $this->expectNotToPerformAssertions();
    }

    public function testAHandlerThatNeverAsksFinishesAndTheRequestIsSimplyTooLate(): void
    {
        $id = $this->queue->enqueue('a', []);
        $queue = $this->queue;
        $runner = new JobRunner($queue, 'w');
        $runner->register('a', new class($queue) implements ContextualJobHandler {
            public function __construct(private readonly JobQueue $queue)
            {
            }

            public function handle(array $payload, array $job = []): void
            {
                $this->queue->cancel((int)$job['id']);
            }
        });

        $runner->run(1);

        $job = $this->queue->find($id);
        self::assertNotNull($job);
        self::assertSame(JobQueue::STATE_COMPLETE, $this->queue->stateOf($job));
        self::assertNotNull($job['cancel_requested_at'], 'the request is on the record');
    }

    // ---------------------------------------------------------------- run

    public function testReleasingAFailedJobGrantsItOneMoreAttemptNow(): void
    {
        $id = $this->queue->enqueue('a', [], 0, 1);
        $this->queue->claim('w');
        $this->queue->fail($id, 'Email plugin is not available');
        self::assertSame(1, $this->queue->stats()['failed']);

        self::assertTrue($this->queue->release($id));

        $job = $this->queue->find($id);
        self::assertNotNull($job);
        self::assertSame(2, (int)$job['max_attempts']);
        self::assertLessThanOrEqual($this->clock->now(), (int)$job['run_after']);
        self::assertSame(JobQueue::STATE_PENDING, $this->queue->stateOf($job));
        self::assertNotNull($this->queue->claim('w'), 'and it is claimable straight away');
    }

    public function testReleasingAJobWaitingOnBackoffRemovesTheWait(): void
    {
        $id = $this->queue->enqueue('a', [], $this->clock->now() + 3600);
        self::assertNull($this->queue->claim('w'));

        self::assertTrue($this->queue->release($id));
        self::assertNotNull($this->queue->claim('w'));
    }

    /**
     * Releasing a job that is already due and unlocked changes no value, and
     * MySQL reports that as zero rows. The answer must still be yes.
     */
    public function testReleasingAJobThatIsAlreadyDueStillAnswersTrue(): void
    {
        $id = $this->queue->enqueue('a', [], $this->clock->now());

        self::assertTrue($this->queue->release($id));
        self::assertTrue($this->queue->release($id), 'and again, with nothing left to change');
    }

    public function testAFinishedOrRunningJobCannotBeReleased(): void
    {
        $done = $this->queue->enqueue('a', []);
        $this->queue->complete($done);
        self::assertFalse($this->queue->release($done));

        $running = $this->queue->enqueue('b', []);
        $this->queue->claim('w');
        self::assertFalse($this->queue->release($running));

        $cancelled = $this->queue->enqueue('c', []);
        $this->queue->cancel($cancelled);
        self::assertFalse($this->queue->release($cancelled));

        self::assertFalse($this->queue->release(999));
    }

    public function testRunOneRunsExactlyTheJobAskedForThroughTheSameCodePath(): void
    {
        $first = $this->queue->enqueue('a', ['n' => 1]);
        $second = $this->queue->enqueue('a', ['n' => 2]);

        $seen = [];
        $runner = new JobRunner($this->queue, 'w');
        $runner->register('a', new class($seen) implements JobHandler {
            /** @param list<int> $seen */
            public function __construct(private array &$seen)
            {
            }

            public function handle(array $payload): void
            {
                $this->seen[] = (int)$payload['n'];
            }
        });

        self::assertSame(JobRunner::OUTCOME_PROCESSED, $runner->runOne($second));
        self::assertSame([2], $seen, 'the second, not the first');
        self::assertSame(JobQueue::STATE_COMPLETE, $this->queue->stateOf($this->queue->find($second) ?? []));
        self::assertSame(JobQueue::STATE_PENDING, $this->queue->stateOf($this->queue->find($first) ?? []));

        self::assertNull($runner->runOne($second), 'a complete job is refused, not run twice');
    }

    public function testRunOneReportsAFailureAndLeavesTheErrorOnTheRow(): void
    {
        $id = $this->queue->enqueue('a', []);
        $runner = new JobRunner($this->queue, 'w');
        $runner->register('a', new class implements JobHandler {
            public function handle(array $payload): void
            {
                throw new \RuntimeException('smtp refused');
            }
        });

        self::assertSame(JobRunner::OUTCOME_FAILED, $runner->runOne($id));
        self::assertStringContainsString('smtp refused', (string)$this->queue->find($id)['last_error']);
    }

    public function testAJobWithNoHandlerFailsWithAMessageSayingSo(): void
    {
        $id = $this->queue->enqueue('nobody.home', []);

        $result = (new JobRunner($this->queue, 'w'))->run(1);

        self::assertSame(1, $result['failed']);
        self::assertStringContainsString('No handler registered for job type: nobody.home', (string)$this->queue->find($id)['last_error']);
    }

    // ---------------------------------------------------------- retention

    public function testRetentionSweepsFinishedJobsAndNeverOpenOnes(): void
    {
        $old = $this->clock->now() - (40 * 86400);
        $completed = $this->queue->enqueue('a', []);
        $this->queue->complete($completed);
        $this->db->update($this->t, ['completed_at' => $old], 'id = ?', [$completed]);
        $cancelled = $this->queue->enqueue('b', []);
        $this->queue->cancel($cancelled);
        $this->db->update($this->t, ['cancelled_at' => $old], 'id = ?', [$cancelled]);
        $failed = $this->queue->enqueue('c', [], 0, 1);
        $this->queue->claimOne($failed, 'w');
        $this->queue->fail($failed, 'x');
        $this->db->update($this->t, ['created_at' => $old], 'id = ?', [$failed]);
        $pending = $this->queue->enqueue('d', []);
        $this->db->update($this->t, ['created_at' => $old], 'id = ?', [$pending]);
        $recent = $this->queue->enqueue('e', []);
        $this->queue->complete($recent);

        self::assertSame(2, $this->queue->purgeFinished(30));
        self::assertNull($this->queue->find($completed));
        self::assertNull($this->queue->find($cancelled));
        self::assertNotNull($this->queue->find($failed), 'a failed job is a problem, not history');
        self::assertNotNull($this->queue->find($pending));
        self::assertNotNull($this->queue->find($recent), 'inside the window');

        self::assertSame(0, $this->queue->purgeFinished(0), 'zero keeps everything');
    }

    public function testASecondSweepChangesNothing(): void
    {
        $completed = $this->queue->enqueue('a', []);
        $this->queue->complete($completed);
        $this->db->update($this->t, ['completed_at' => $this->clock->now() - (40 * 86400)], 'id = ?', [$completed]);
        $this->queue->enqueue('b', []);
        $this->queue->purgeFinished(30);

        $before = $this->db->fetchAll("SELECT * FROM {$this->t} ORDER BY id");
        self::assertSame(0, $this->queue->purgeFinished(30));
        self::assertSame($before, $this->db->fetchAll("SELECT * FROM {$this->t} ORDER BY id"));
    }
}
