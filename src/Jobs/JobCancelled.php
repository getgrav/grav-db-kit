<?php

declare(strict_types=1);

namespace TrilbyMedia\GravDbKit\Jobs;

/**
 * Thrown by a handler that stopped at a transaction boundary because somebody
 * asked for the job to be cancelled.
 *
 * Only ever thrown *between* pieces of work, never inside one: the handler
 * asks {@see JobCancellation::checkpoint()} after a transaction commits and
 * before the next begins, and this is what the checkpoint throws. The runner
 * catches it and marks the job cancelled rather than failed — the handler did
 * nothing wrong, it obeyed — and gives back the attempt the claim consumed.
 *
 * Deliberately not a RuntimeException. A handler that catches Throwable around
 * a chunk to log and carry on must not catch this by accident and carry on
 * past a cancellation; extending Exception directly and documenting the rule
 * is the honest version of that.
 */
final class JobCancelled extends \Exception
{
    public function __construct(public readonly int $jobId)
    {
        parent::__construct("Job {$jobId} was cancelled at a boundary.");
    }
}
