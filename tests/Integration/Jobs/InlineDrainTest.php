<?php

declare(strict_types=1);

namespace TrilbyMedia\GravDbKit\Tests\Integration\Jobs;

use PHPUnit\Framework\TestCase;
use TrilbyMedia\GravDbKit\Database\Connection;
use TrilbyMedia\GravDbKit\Jobs\InlineDrain;
use TrilbyMedia\GravDbKit\Jobs\JobDeadline;
use TrilbyMedia\GravDbKit\Jobs\JobHandler;
use TrilbyMedia\GravDbKit\Jobs\JobQueue;
use TrilbyMedia\GravDbKit\Jobs\JobRunner;
use TrilbyMedia\GravDbKit\Jobs\PendingDrain;
use TrilbyMedia\GravDbKit\Tests\Jobs\Support\JobsDatabase;

/**
 * A request runs its own jobs once the response has gone, and nobody else's.
 *
 * The whole feature in one sentence: an email goes out a second after the
 * request that asked for it rather than up to a minute later, and it costs
 * nothing — on PHP-FPM the page is already in the browser before the first
 * handler runs. What has to be true for that to be safe is what these prove:
 * only this request's jobs are touched, a cron worker in the same second cannot
 * double one, nothing the drain does can reach the response or throw, and a
 * second shutdown hook calling it again does nothing at all.
 */
final class InlineDrainTest extends TestCase
{
    private int $now = 2_000_000;

    /**
     * The end-to-end case: a request queues, the drain runs exactly that job,
     * and there is nothing left for the tick that follows.
     */
    public function testTheRequestRunsTheJobItQueuedAndCronFindsNothingLeft(): void
    {
        $db = JobsDatabase::fresh();
        $pending = new PendingDrain();
        $queue = $this->queue($db, $pending);

        $id = $queue->enqueue('order.confirmation_email', ['order_id' => 7]);
        self::assertSame([$id], $pending->ids(), 'queueing tells the trigger');

        $handler = new RecordingHandler();
        $result = $this->drain($pending, $queue, ['order.confirmation_email' => $handler])->run();

        self::assertSame(1, $result['processed']);
        self::assertSame([['order_id' => 7]], $handler->payloads);

        $row = $queue->find($id);
        self::assertNotNull($row['completed_at'], 'the job is finished, not merely claimed');

        // What the worker would find a minute later.
        $cron = new JobRunner($queue, 'cron', fn (): int => $this->now);
        $cron->register('order.confirmation_email', $handler);
        self::assertSame(
            ['processed' => 0, 'failed' => 0, 'deferred' => 0],
            $cron->run(5),
            'the tick has nothing left to do'
        );
    }

    /** Somebody else's backlog is not this shopper's problem. */
    public function testOnlyThisRequestsJobsAreRun(): void
    {
        $db = JobsDatabase::fresh();
        $backlog = $this->queue($db, new PendingDrain());
        $backlog->enqueue('order.confirmation_email', ['order_id' => 1]);
        $backlog->enqueue('order.confirmation_email', ['order_id' => 2]);

        $pending = new PendingDrain();
        $mine = $this->queue($db, $pending);
        $mineId = $mine->enqueue('order.confirmation_email', ['order_id' => 3]);

        $handler = new RecordingHandler();
        $result = $this->drain($pending, $mine, ['order.confirmation_email' => $handler])->run();

        self::assertSame(1, $result['processed']);
        self::assertSame([['order_id' => 3]], $handler->payloads, 'the two older jobs were left alone');

        $open = (int)$db->fetchValue('SELECT COUNT(*) FROM ' . JobsDatabase::table() . ' WHERE completed_at IS NULL');
        self::assertSame(2, $open);
        self::assertNotNull(
            $db->fetchRow('SELECT completed_at FROM ' . JobsDatabase::table() . ' WHERE id = ?', [$mineId])['completed_at']
        );
    }

    /**
     * A cron worker landing in the same second as the drain.
     *
     * The claim is the same conditional UPDATE either way, so whichever gets
     * there first runs the job and the other is handed nothing.
     */
    public function testACronWorkerInTheSameSecondCannotRunTheJobTwice(): void
    {
        $db = JobsDatabase::fresh();
        $pending = new PendingDrain();
        $queue = $this->queue($db, $pending);
        $queue->enqueue('order.confirmation_email', ['order_id' => 4]);

        $handler = new RecordingHandler();

        // Cron gets there first and takes it.
        $cron = new JobRunner($queue, 'cron', fn (): int => $this->now);
        $cron->register('order.confirmation_email', $handler);
        self::assertSame(1, $cron->run(5)['processed']);

        $result = $this->drain($pending, $queue, ['order.confirmation_email' => $handler])->run();

        self::assertSame(0, $result['processed']);
        self::assertSame(1, $result['skipped'], 'the drain was refused the claim, not given a second run');
        self::assertCount(1, $handler->payloads, 'the handler ran once');
    }

    /**
     * Grav closes the session before onShutdown. A handler that reaches for it
     * anyway fails, and the drain carries on — it does not reopen anything.
     */
    public function testAHandlerThatTouchesTheClosedSessionFailsCleanly(): void
    {
        $db = JobsDatabase::fresh();
        $pending = new PendingDrain();
        $queue = $this->queue($db, $pending);
        $badId = $queue->enqueue('order.confirmation_email', []);
        $queue->enqueue('order.shipped_email', ['order_id' => 5]);

        $session = new ClosedSession();
        $good = new RecordingHandler();

        $result = $this->drain($pending, $queue, [
            'order.confirmation_email' => new TouchesSession($session),
            'order.shipped_email' => $good,
        ])->run();

        self::assertSame(1, $result['failed']);
        self::assertSame(1, $result['processed'], 'the next job still ran');
        self::assertSame(0, $session->reopened, 'nothing reopened the session');

        $row = $db->fetchRow('SELECT last_error FROM ' . JobsDatabase::table() . ' WHERE id = ?', [$badId]);
        self::assertStringContainsString('session', (string)$row['last_error']);
    }

    /** Whatever a handler prints, the client never sees it. */
    public function testNothingAHandlerEchoesReachesTheResponse(): void
    {
        $db = JobsDatabase::fresh();
        $pending = new PendingDrain();
        $queue = $this->queue($db, $pending);
        $queue->enqueue('order.confirmation_email', []);

        ob_start();
        $this->drain($pending, $queue, ['order.confirmation_email' => new Shouts()])->run();
        $leaked = (string)ob_get_clean();

        self::assertSame('', $leaked, 'the drain swallows its own output');
    }

    /** One setting, and the jobs are left for the worker untouched. */
    public function testAnEmptyListDoesNothingAtAll(): void
    {
        $db = JobsDatabase::fresh();
        $pending = new PendingDrain();
        $queue = $this->queue($db, $pending);

        $result = $this->drain($pending, $queue, [])->run();

        self::assertSame(0, $result['jobs']);
        self::assertSame(0, (int)$db->fetchValue('SELECT COUNT(*) FROM ' . JobsDatabase::table()));
    }

    /**
     * The budget is a wall, not a suggestion: what it does not reach is left
     * pending rather than run late.
     */
    public function testJobsPastTheBudgetAreLeftForTheWorker(): void
    {
        $db = JobsDatabase::fresh();
        $pending = new PendingDrain();
        $queue = $this->queue($db, $pending);
        $queue->enqueue('order.confirmation_email', ['order_id' => 1]);
        $queue->enqueue('order.confirmation_email', ['order_id' => 2]);
        $queue->enqueue('order.confirmation_email', ['order_id' => 3]);

        // Each job costs two seconds of the runner's clock, against a budget
        // of three: the first fits, the second starts, the third does not.
        $runner = new JobRunner($queue, 'drain', function (): int {
            return $this->now;
        });
        $runner->register('order.confirmation_email', new CostsTime(function (int $seconds): void {
            $this->now += $seconds;
        }, 2));

        $result = (new InlineDrain($pending, $runner, 3, null, true))->run();

        self::assertSame(2, $result['processed']);
        self::assertSame(1, $result['skipped']);
        self::assertSame(
            1,
            (int)$db->fetchValue('SELECT COUNT(*) FROM ' . JobsDatabase::table() . ' WHERE completed_at IS NULL'),
            'the third is still queued for the worker'
        );
    }

    /** A handler queueing more work does not make the request chase it. */
    public function testWorkQueuedByAHandlerIsLeftForTheNextTick(): void
    {
        $db = JobsDatabase::fresh();
        $pending = new PendingDrain();
        $queue = $this->queue($db, $pending);
        $queue->enqueue('order.confirmation_email', []);

        $result = $this->drain($pending, $queue, [
            'order.confirmation_email' => new QueuesMore($queue),
        ])->run();

        self::assertSame(1, $result['processed'], 'only the job the request queued');
        self::assertSame(
            1,
            (int)$db->fetchValue('SELECT COUNT(*) FROM ' . JobsDatabase::table() . ' WHERE completed_at IS NULL'),
            'the follow-up waits for the worker'
        );
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
    }

    /** The line an operator reads in the log says which hosting case ran. */
    public function testTheLogLineNamesTheHostingCase(): void
    {
        $db = JobsDatabase::fresh();
        $pending = new PendingDrain();
        $queue = $this->queue($db, $pending);
        $queue->enqueue('order.confirmation_email', []);

        $lines = [];
        $runner = new JobRunner($queue, 'drain', fn (): int => $this->now);
        $runner->register('order.confirmation_email', new RecordingHandler());

        (new InlineDrain($pending, $runner, 3, static function (string $line) use (&$lines): void {
            $lines[] = $line;
        }, false))->run();

        self::assertCount(1, $lines, 'one line per request, not one per job');
        self::assertStringContainsString('still being read by the client', $lines[0]);
        self::assertStringContainsString('no fastcgi_finish_request', $lines[0]);
    }

    // ---------------------------------------------------- two shutdown hooks

    /**
     * A plugin wires the drain to Grav's onShutdown and to a plain
     * register_shutdown_function, because there are requests on which Grav
     * never fires onShutdown. Whichever hook gets there first drains; the
     * second call is a no-op.
     */
    public function testASecondCallOnTheSameDrainDoesNothing(): void
    {
        $db = JobsDatabase::fresh();
        $pending = new PendingDrain();
        $queue = $this->queue($db, $pending);
        $queue->enqueue('order.confirmation_email', ['order_id' => 1]);

        $handler = new RecordingHandler();
        $drain = $this->drain($pending, $queue, ['order.confirmation_email' => $handler]);

        self::assertSame(1, $drain->run()['processed']);
        self::assertSame(
            ['processed' => 0, 'failed' => 0, 'deferred' => 0, 'skipped' => 0, 'jobs' => 0],
            $drain->run(),
            'the second hook finds nothing to do'
        );
        self::assertCount(1, $handler->payloads);
    }

    /**
     * Each hook usually builds its own drain. The list they share is what
     * makes the second one a no-op — including for the work a handler queued
     * during the first, which belongs to the worker, not to the second hook.
     */
    public function testTwoDrainsOverOnePendingListRunEachJobOnceAndChaseNothing(): void
    {
        $db = JobsDatabase::fresh();
        $pending = new PendingDrain();
        $queue = $this->queue($db, $pending);
        $queue->enqueue('order.confirmation_email', []);

        $handlers = ['order.confirmation_email' => new QueuesMore($queue)];
        $onShutdown = $this->drain($pending, $queue, $handlers);
        $fallback = $this->drain($pending, $queue, $handlers);

        $first = $onShutdown->run();
        $second = $fallback->run();

        self::assertSame(1, $first['processed']);
        self::assertSame(0, $second['jobs'], 'the fallback found the list already taken');
        self::assertTrue($pending->drained());
        self::assertSame([], $pending->ids(), 'the follow-up was not collected for a later hook');
        self::assertSame(
            1,
            (int)$db->fetchValue('SELECT COUNT(*) FROM ' . JobsDatabase::table() . ' WHERE completed_at IS NULL'),
            'the follow-up waits for the worker'
        );
    }

    /** It does not matter which hook comes first. */
    public function testTheFallbackMayBeTheOneThatDrains(): void
    {
        $db = JobsDatabase::fresh();
        $pending = new PendingDrain();
        $queue = $this->queue($db, $pending);
        $queue->enqueue('order.confirmation_email', ['order_id' => 9]);

        $handler = new RecordingHandler();
        $fallback = $this->drain($pending, $queue, ['order.confirmation_email' => $handler]);
        $onShutdown = $this->drain($pending, $queue, ['order.confirmation_email' => $handler]);

        self::assertSame(1, $fallback->run()['processed']);
        self::assertSame(0, $onShutdown->run()['jobs']);
        self::assertSame([['order_id' => 9]], $handler->payloads);
    }

    // ------------------------------------------------ never throws, never prints

    /**
     * A request that died with a transaction open still has it open when the
     * shutdown hooks run. Running its jobs there would send an email for work
     * that is about to be rolled back.
     */
    public function testNothingRunsWhileTheConnectionIsInsideATransaction(): void
    {
        $db = JobsDatabase::fresh();
        $pending = new PendingDrain();
        $queue = $this->queue($db, $pending);

        // Begun on the PDO, the way code outside Connection::transaction() does.
        $db->pdo()->beginTransaction();
        $queue->enqueue('order.confirmation_email', []);

        $lines = [];
        $handler = new RecordingHandler();
        $runner = new JobRunner($queue, 'drain', fn (): int => $this->now);
        $runner->register('order.confirmation_email', $handler);
        $result = (new InlineDrain($pending, $runner, 3, static function (string $line) use (&$lines): void {
            $lines[] = $line;
        }, true))->run();

        $db->pdo()->rollBack();

        self::assertSame([], $handler->payloads, 'the handler never ran');
        self::assertSame(1, $result['skipped']);
        self::assertStringContainsString('inside a transaction', $lines[0] ?? '');
        self::assertSame(0, (int)$db->fetchValue('SELECT COUNT(*) FROM ' . JobsDatabase::table()), 'and the job went with the rollback');
    }

    /**
     * The database going away between jobs is the realistic way the runner
     * itself breaks. The drain reports it and returns; it does not throw.
     */
    public function testARunnerThatBreaksDoesNotThrow(): void
    {
        $db = JobsDatabase::fresh();
        $pending = new PendingDrain();
        $queue = $this->queue($db, $pending);
        $queue->enqueue('order.confirmation_email', []);
        $db->run('DROP TABLE ' . JobsDatabase::table());

        $lines = [];
        $runner = new JobRunner($queue, 'drain', fn (): int => $this->now);
        $runner->register('order.confirmation_email', new RecordingHandler());

        $result = (new InlineDrain($pending, $runner, 3, static function (string $line) use (&$lines): void {
            $lines[] = $line;
        }, true))->run();

        self::assertSame(0, $result['processed']);
        self::assertSame(1, $result['jobs']);
        self::assertStringContainsString('stopped early', $lines[0] ?? '');
    }

    /** A logger that prints, or throws, is swallowed like everything else. */
    public function testALoggerThatPrintsOrThrowsNeitherLeaksNorThrows(): void
    {
        $db = JobsDatabase::fresh();
        $pending = new PendingDrain();
        $queue = $this->queue($db, $pending);
        $queue->enqueue('order.confirmation_email', []);

        $runner = new JobRunner($queue, 'drain', fn (): int => $this->now);
        $runner->register('order.confirmation_email', new Shouts());

        $level = ob_get_level();
        ob_start();
        $result = (new InlineDrain($pending, $runner, 3, static function (string $line): void {
            echo $line;

            throw new \RuntimeException('the log file is not writable');
        }, true))->run();
        $leaked = (string)ob_get_clean();

        self::assertSame(1, $result['processed']);
        self::assertSame('', $leaked);
        self::assertSame($level, ob_get_level());
    }

    /**
     * A handler that opens buffers of its own and leaves them open does not
     * leave them for the caller, and what it wrote into them goes nowhere.
     */
    public function testBuffersAHandlerLeavesOpenAreClosedAndDiscarded(): void
    {
        $db = JobsDatabase::fresh();
        $pending = new PendingDrain();
        $queue = $this->queue($db, $pending);
        $queue->enqueue('order.confirmation_email', []);

        $level = ob_get_level();
        ob_start();
        $this->drain($pending, $queue, ['order.confirmation_email' => new LeavesBuffersOpen()])->run();
        $leaked = (string)ob_get_clean();

        self::assertSame('', $leaked);
        self::assertSame($level, ob_get_level(), 'the caller\'s buffer stack is as it was');
    }

    /** A warning with display_errors on is output too, and goes the same way. */
    public function testAWarningWithDisplayErrorsOnDoesNotReachTheResponse(): void
    {
        $db = JobsDatabase::fresh();
        $pending = new PendingDrain();
        $queue = $this->queue($db, $pending);
        $queue->enqueue('order.confirmation_email', []);

        $display = ini_get('display_errors');
        ini_set('display_errors', '1');
        set_error_handler(static fn (): bool => false);
        try {
            ob_start();
            $this->drain($pending, $queue, ['order.confirmation_email' => new Warns()])->run();
            $leaked = (string)ob_get_clean();
            $restored = ini_get('display_errors');
        } finally {
            restore_error_handler();
            ini_set('display_errors', (string)$display);
        }

        self::assertSame('', $leaked);
        self::assertSame('1', $restored, 'display_errors is put back once the drain is done');
    }

    private function queue(Connection $db, PendingDrain $pending): JobQueue
    {
        return new JobQueue($db, JobsDatabase::tables(), 300, null, fn (): int => $this->now, $pending);
    }

    /** @param array<string, JobHandler> $handlers */
    private function drain(PendingDrain $pending, JobQueue $queue, array $handlers): InlineDrain
    {
        $runner = new JobRunner($queue, 'drain', fn (): int => $this->now);
        foreach ($handlers as $type => $handler) {
            $runner->register($type, $handler);
        }
        $runner->withDeadline(new JobDeadline(0));

        return new InlineDrain($pending, $runner, 3, null, true);
    }
}

/** Remembers what it was handed. */
final class RecordingHandler implements JobHandler
{
    /** @var list<array<string, mixed>> */
    public array $payloads = [];

    public function handle(array $payload): void
    {
        $this->payloads[] = $payload;
    }
}

/** A session that has been closed, and says so rather than reopening. */
final class ClosedSession
{
    public int $reopened = 0;

    public function get(string $key): never
    {
        throw new \RuntimeException('The session is closed; a job may not read it.');
    }

    public function start(): void
    {
        $this->reopened++;
    }
}

/** The handler a plugin author writes before reading the docs. */
final class TouchesSession implements JobHandler
{
    public function __construct(private readonly ClosedSession $session)
    {
    }

    public function handle(array $payload): void
    {
        $this->session->get('cart');
    }
}

/** Prints, the way a handler with a stray var_dump in it does. */
final class Shouts implements JobHandler
{
    public function handle(array $payload): void
    {
        echo 'this must never reach the client';
    }
}

/** Moves the clock on, so a budget can be spent without waiting. */
final class CostsTime implements JobHandler
{
    /** @var callable(int): void */
    private $advance;

    /** @param callable(int): void $advance */
    public function __construct(callable $advance, private readonly int $seconds)
    {
        $this->advance = $advance;
    }

    public function handle(array $payload): void
    {
        ($this->advance)($this->seconds);
    }
}

/** Queues a follow-up, the way the import handler does. */
final class QueuesMore implements JobHandler
{
    public function __construct(private readonly JobQueue $queue)
    {
    }

    public function handle(array $payload): void
    {
        $this->queue->enqueue('order.confirmation_email', ['follow_up' => true]);
    }
}

/** Opens two buffers of its own, writes into both and closes neither. */
final class LeavesBuffersOpen implements JobHandler
{
    public function handle(array $payload): void
    {
        ob_start();
        echo 'first';
        ob_start();
        echo 'second';
    }
}

/** Raises a warning, which PHP prints when display_errors is on. */
final class Warns implements JobHandler
{
    public function handle(array $payload): void
    {
        trigger_error('a handler warning', E_USER_WARNING);
    }
}
