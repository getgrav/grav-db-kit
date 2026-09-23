<?php

declare(strict_types=1);

namespace TrilbyMedia\GravDbKit\Jobs;

/**
 * A handler that was still going when its deadline passed.
 *
 * Caught by the runner's ordinary `catch (\Throwable)`, so the job is marked
 * failed with this message, keeps its attempt, and retries with backoff like
 * any other failure. That is deliberate: a provider that hung once usually
 * answers on the next try, and a provider that hangs three times belongs in
 * the failed bucket where somebody will read it.
 *
 * A RuntimeException rather than an Exception of its own kind, because a
 * handler catching Throwable around one chunk of work and carrying on is doing
 * something reasonable, and there is no correctness reason to stop it — unlike
 * a cancellation, which must not be swallowed.
 *
 * @see JobDeadline for why an alarm is available on some hosts and not others
 */
final class JobTimedOut extends \RuntimeException
{
    /**
     * @param int $seconds the deadline that was passed
     * @param int|null $elapsed how long the handler actually took, where that
     *        is known — the after-the-fact path measures it, the alarm stops
     *        the handler at the deadline and so has nothing more to say
     */
    public function __construct(
        public readonly int $seconds,
        public readonly ?int $elapsed = null,
    ) {
        $overran = $elapsed === null ? '' : " (it ran for {$elapsed}s)";

        parent::__construct(
            "The job passed its {$seconds}s deadline{$overran}. "
            . 'Raise the job timeout setting, or give the handler a shorter timeout of its own.'
        );
    }
}
