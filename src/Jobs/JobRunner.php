<?php

declare(strict_types=1);

namespace TrilbyMedia\GravDbKit\Jobs;

use TrilbyMedia\GravDbKit\Support\Clock;

/**
 * Drains the queue within a time budget. Runs inside the scheduler's
 * every-minute worker invocation, so the budget stays under the tick
 * interval to avoid overlapping workers piling up (overlap is still safe —
 * claiming is atomic — just wasteful).
 *
 * A pass does not stop the first time claim() comes back empty. A handler is
 * allowed to say "not yet" (see DeferJob), and the job it is waiting on has
 * usually just run in this same pass, so ending here would leave somebody's
 * email sitting until the next tick a minute later for no reason. Instead the
 * runner asks the queue when the next job is due and waits for it, as long as
 * the budget covers the wait.
 */
final class JobRunner
{
    /** Longest single wait for a job that is not due yet. */
    private const MAX_IDLE_SLEEP = 10;

    public const OUTCOME_PROCESSED = 'processed';
    public const OUTCOME_FAILED = 'failed';
    public const OUTCOME_DEFERRED = 'deferred';
    public const OUTCOME_CANCELLED = 'cancelled';

    /** @var array<string, JobHandler> */
    private array $handlers = [];

    /** @var callable(): int */
    private $clock;

    /** @var callable(int): void */
    private $sleep;

    /**
     * How long one handler may take, or null for no limit.
     *
     * Held rather than passed per job so every caller of execute() gets the
     * same rule: the daemon, the inline drain and a CLI's `jobs:run` all run
     * handlers through here, and a deadline that only one of them enforced
     * would be a deadline nobody could rely on.
     */
    private ?JobDeadline $deadline = null;

    /**
     * @param string $workerId what `locked_by` says while this runner holds a
     *        job; at most 64 characters. hostWorkerId() is the usual answer.
     * @param Clock|(callable(): int)|null $clock the current unix time.
     *        Defaults to the queue's own clock, and must: this pass decides
     *        whether to wait for a job by comparing its run_after — a time the
     *        queue wrote — against this, and two clocks that disagree either
     *        wait forever or never wait at all.
     * @param (callable(int): void)|null $sleep how to wait $seconds; injectable
     *        so a test can prove the waiting behaviour without waiting
     */
    public function __construct(
        private readonly JobQueue $queue,
        private readonly string $workerId,
        Clock|callable|null $clock = null,
        ?callable $sleep = null,
    ) {
        $this->clock = match (true) {
            $clock instanceof Clock => $clock->now(...),
            $clock !== null => $clock,
            default => $queue->now(...),
        };
        $this->sleep = $sleep ?? static function (int $seconds): void {
            sleep($seconds);
        };
    }

    /**
     * Host and pid, clipped to the `locked_by` column: the worker id every
     * in-tree copy of this code built by hand.
     */
    public static function hostWorkerId(): string
    {
        $host = gethostname();

        return substr(($host === false ? 'unknown' : $host) . ':' . (getmypid() ?: 0), 0, 64);
    }

    /** The queue this runner claims from. */
    public function queue(): JobQueue
    {
        return $this->queue;
    }

    /**
     * Give every job this runner executes a time limit.
     *
     * A setter rather than a constructor argument because most runners never
     * set one, and the four arguments above are what every caller already
     * passes. A runner with no deadline set behaves exactly as it always has.
     */
    public function withDeadline(?JobDeadline $deadline): self
    {
        $this->deadline = $deadline;

        return $this;
    }

    /** What stops a hung handler here, for a command that wants to say so. */
    public function deadline(): ?JobDeadline
    {
        return $this->deadline;
    }

    public function register(string $type, JobHandler $handler): void
    {
        $this->handlers[$type] = $handler;
    }

    /**
     * Take every handler a registry collected.
     *
     * The registry has already refused malformed and duplicate types, so its
     * contents go in as they are.
     */
    public function registerAll(JobHandlerRegistry $registry): self
    {
        foreach ($registry->all() as $type => $handler) {
            $this->handlers[$type] = $handler;
        }

        return $this;
    }

    /** @return list<string> the types this runner can execute */
    public function types(): array
    {
        return array_keys($this->handlers);
    }

    /**
     * Drain the queue until it is empty, the budget is spent, or `$maxJobs`
     * jobs have been claimed.
     *
     * @param int $maxJobs 0 for no cap; Forum Pro's worker capped a pass at
     *        fifty jobs, and a plugin that wants the same keeps it here
     * @return array{processed: int, failed: int, deferred: int}
     */
    public function run(int $timeBudgetSeconds = 50, int $maxJobs = 0): array
    {
        $deadline = ($this->clock)() + $timeBudgetSeconds;
        $processed = 0;
        $failed = 0;
        $deferred = 0;
        $claimed = 0;

        while (($now = ($this->clock)()) < $deadline) {
            if ($maxJobs > 0 && $claimed >= $maxJobs) {
                break;
            }

            $job = $this->queue->claim($this->workerId);
            if ($job === null) {
                if (!$this->waitForNextJob($now, $deadline)) {
                    break;
                }

                continue;
            }

            $claimed++;
            $outcome = $this->execute($job);
            match ($outcome) {
                self::OUTCOME_PROCESSED => $processed++,
                self::OUTCOME_DEFERRED => $deferred++,
                self::OUTCOME_CANCELLED => null,
                default => $failed++,
            };
        }

        return ['processed' => $processed, 'failed' => $failed, 'deferred' => $deferred];
    }

    /**
     * Run one claimed job through its handler and settle the row.
     *
     * Split out of run() so a CLI's `jobs:run <id>` can run a single job in
     * the same code path the worker uses, rather than a second one that would
     * drift. The claim is the caller's: run() claims in a loop, runOne() claims
     * the one it was asked for, the daemon claims one at a time.
     *
     * A cancellation requested while the job was waiting to be claimed is
     * honoured before the handler starts. One requested while the handler is
     * running is honoured wherever the handler next asks JobCancellation, and
     * a handler that never asks finishes its work — the request is then simply
     * too late, and the row says so.
     *
     * Throws only when settling the row fails, which in practice means the
     * connection is gone; everything a handler throws is caught and recorded.
     * A completion write that fails is caught like a handler failure and the
     * job retried, which is why handlers must be idempotent.
     *
     * @param array<string, mixed> $job the claimed row
     */
    public function execute(array $job): string
    {
        $jobId = (int)$job['id'];
        $type = (string)$job['type'];

        if (($job['cancel_requested_at'] ?? null) !== null) {
            $this->queue->markCancelled($jobId);

            return self::OUTCOME_CANCELLED;
        }

        $handler = $this->handlers[$type] ?? null;
        if ($handler === null) {
            $this->queue->fail($jobId, "No handler registered for job type: {$type}");

            return self::OUTCOME_FAILED;
        }

        try {
            $payload = json_decode((string)($job['payload_json'] ?? '{}'), true, 512, JSON_THROW_ON_ERROR);
            $payload = \is_array($payload) ? $payload : [];

            $this->deadline?->start();
            try {
                if ($handler instanceof ContextualJobHandler) {
                    $handler->handle($payload, $job);
                } else {
                    $handler->handle($payload);
                }
            } catch (\Throwable $e) {
                // The handler's own error is the one worth reading, so the
                // deadline is disarmed without being allowed to relabel it.
                $this->deadline?->stop();

                throw $e;
            }
            // Throws JobTimedOut on a host with no alarm, where the only way
            // to notice an overrun is to look at the clock afterwards.
            $this->deadline?->finish();

            $this->queue->complete($jobId);

            return self::OUTCOME_PROCESSED;
        } catch (DeferJob $deferral) {
            // Early, not broken: the job goes back with its attempt
            // returned and nothing written to last_error.
            $this->queue->defer($jobId, ($this->clock)() + $deferral->seconds);

            return self::OUTCOME_DEFERRED;
        } catch (JobCancelled) {
            // Stopped at a boundary because it was asked to. Everything the
            // handler committed before the checkpoint stands; nothing after it
            // was started.
            $this->queue->markCancelled($jobId);

            return self::OUTCOME_CANCELLED;
        } catch (\Throwable $e) {
            $this->queue->fail($jobId, $e::class . ': ' . $e->getMessage());

            return self::OUTCOME_FAILED;
        }
    }

    /**
     * Run these jobs and nothing else, within a budget.
     *
     * What the inline drain uses: the request that queued the work runs the
     * work it queued, after the response has gone to the client, and leaves the
     * rest of the queue to cron and the daemon. That restraint is the whole
     * design — a visitor clicking a button must not pay for a backlog somebody
     * else's import left behind.
     *
     * Every claim is the same conditional UPDATE the worker makes, so a cron
     * tick arriving in the same second cannot run a job this pass already has.
     * A job that could not be claimed, or that the budget did not reach, is
     * left exactly where it was: pending, for whoever gets there next.
     *
     * The budget is checked before each job rather than during one, because
     * the only thing that can stop a handler part-way is the per-job deadline
     * — see JobDeadline.
     *
     * @param list<int> $jobIds in the order they were queued
     * @return array{processed: int, failed: int, deferred: int, skipped: int}
     */
    public function runIds(array $jobIds, int $timeBudgetSeconds): array
    {
        $deadline = ($this->clock)() + $timeBudgetSeconds;
        $processed = 0;
        $failed = 0;
        $deferred = 0;
        $skipped = 0;

        foreach ($jobIds as $jobId) {
            if (($this->clock)() >= $deadline) {
                $skipped++;

                continue;
            }

            $job = $this->queue->claimOne($jobId, $this->workerId);
            if ($job === null) {
                $skipped++;

                continue;
            }

            $outcome = $this->execute($job);
            match ($outcome) {
                self::OUTCOME_PROCESSED => $processed++,
                self::OUTCOME_DEFERRED => $deferred++,
                self::OUTCOME_CANCELLED => null,
                default => $failed++,
            };
        }

        return ['processed' => $processed, 'failed' => $failed, 'deferred' => $deferred, 'skipped' => $skipped];
    }

    /**
     * Claim one specific job and run it now, whatever it was waiting for.
     *
     * The CLI twin of an admin's Run button, for the operator who wants the
     * job done in this shell rather than at the next tick. A job that cannot
     * be released — complete, cancelled, or running on another worker — is
     * refused with null rather than run twice.
     */
    public function runOne(int $jobId): ?string
    {
        if (!$this->queue->release($jobId)) {
            return null;
        }

        $job = $this->queue->claimOne($jobId, $this->workerId);
        if ($job === null) {
            return null;
        }

        return $this->execute($job);
    }

    /**
     * Wait for the next job to become due, and say whether waiting is worth it.
     *
     * False means stop the pass: either nothing is queued at all, or what is
     * queued comes due after this worker's budget runs out and belongs to the
     * next tick. The wait is capped at MAX_IDLE_SLEEP so a job scheduled an
     * hour out never holds a worker process open.
     */
    private function waitForNextJob(int $now, int $deadline): bool
    {
        $due = $this->queue->nextRunAfter($now);
        if ($due === null || $due > $deadline) {
            return false;
        }

        $wait = min($due - $now, $deadline - $now, self::MAX_IDLE_SLEEP);
        if ($wait <= 0) {
            return false;
        }

        ($this->sleep)($wait);

        return true;
    }
}
