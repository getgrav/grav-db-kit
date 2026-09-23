<?php

declare(strict_types=1);

namespace TrilbyMedia\GravDbKit\Jobs;

/**
 * "Not now, ask me again in a moment" — thrown by a handler that cannot do its
 * work yet and does not want that counted against it.
 *
 * The queue already had two answers for a job: it worked, or it threw. Neither
 * fits work that is merely early. An email whose content is still being filled
 * in by another job is not a failed send — retrying it as a failure would burn
 * one of its three attempts, push it behind an exponential backoff measured in
 * minutes, and write a `last_error` an operator would read as a bug. So this is
 * a third answer: JobRunner catches it, hands the job back to the queue with a
 * later `run_after` and the attempt it just consumed given back, and moves on.
 * Nothing is logged, because nothing went wrong.
 *
 * An exception rather than a return value because JobHandler::handle() returns
 * void and every handler in the wild implements that signature; a handler opts
 * into deferral by throwing, and one that never heard of it behaves exactly as
 * it always has.
 *
 * @see JobQueue::defer()
 */
final class DeferJob extends \RuntimeException
{
    /** How long a handler that gives no opinion waits before being asked again. */
    public const DEFAULT_SECONDS = 5;

    /** How far ahead to move the job, in seconds. Never below 1. */
    public readonly int $seconds;

    /**
     * @param int $seconds floored at 1 — a deferral to "now" would be
     *        re-claimed inside the same loop iteration and spin.
     */
    public function __construct(int $seconds = self::DEFAULT_SECONDS, string $reason = '')
    {
        $this->seconds = max(1, $seconds);

        parent::__construct($reason !== '' ? $reason : "job deferred for {$this->seconds}s");
    }
}
