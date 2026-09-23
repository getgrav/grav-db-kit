<?php

declare(strict_types=1);

namespace TrilbyMedia\GravDbKit\Jobs;

/**
 * One kind of background work.
 *
 * Throw to mark the job failed: it retries with backoff until its attempts
 * run out. Handlers must be idempotent, because a worker can die after the
 * work and before the completion write, and the stale-lock window then hands
 * the job to somebody else.
 */
interface JobHandler
{
    /**
     * @param array<string, mixed> $payload
     */
    public function handle(array $payload): void;
}
