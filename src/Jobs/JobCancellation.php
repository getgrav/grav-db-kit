<?php

declare(strict_types=1);

namespace TrilbyMedia\GravDbKit\Jobs;

/**
 * The one call a long-running handler makes between its transactions.
 *
 * Cancelling a running job cannot kill anything: a handler halfway through a
 * transaction has rows that are either all committed or all rolled back, and
 * a signal delivered in between those two states is how a store ends up with
 * an order whose status changed and whose history entry was never written.
 * So cancellation is a flag, and the flag is read here, at a boundary the
 * handler chooses — after one record's transaction commits and before the
 * next begins, after one import chunk lands and before the next is read.
 *
 * A handler that does one thing in one transaction never needs this. A handler
 * that does two hundred things — a bulk status change, a CSV import — calls it
 * once per thing, and the cost is one indexed read per call.
 *
 * `$job` is the queue row the runner handed a ContextualJobHandler; a plain
 * JobHandler has no row and no cancellation, which is fine, because a handler
 * with no boundaries has nothing to stop at.
 */
final class JobCancellation
{
    private function __construct()
    {
    }

    /**
     * Stop here if this job has been asked to.
     *
     * @param array<string, mixed> $job the queue row, as handed to the handler
     * @throws JobCancelled when somebody pressed Cancel since the last checkpoint
     */
    public static function checkpoint(JobQueue $queue, array $job): void
    {
        $jobId = (int)($job['id'] ?? 0);
        if ($jobId <= 0) {
            return;
        }

        if ($queue->cancelRequested($jobId)) {
            throw new JobCancelled($jobId);
        }
    }
}
