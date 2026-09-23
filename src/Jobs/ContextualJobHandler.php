<?php

declare(strict_types=1);

namespace TrilbyMedia\GravDbKit\Jobs;

/**
 * A handler that also wants the queue row it was claimed from.
 *
 * Almost every handler needs nothing but its payload, and JobHandler stays
 * that simple. The exception is a handler that has to reason about time or
 * about itself: an email that holds itself back while another job finishes
 * needs `created_at` to know when to stop holding, and a long handler that
 * honours Cancel needs its own `id` to ask JobCancellation. Both live on the
 * row, not in the payload.
 *
 * The extra parameter is optional and the interface extends JobHandler, so a
 * handler already implementing `handle(array $payload)` is unaffected and
 * JobRunner can hand every handler its payload the way it always has. Only a
 * handler that says it wants the row is given one.
 *
 * @see JobRunner::execute()
 */
interface ContextualJobHandler extends JobHandler
{
    /**
     * @param array<string, mixed> $payload
     * @param array<string, mixed> $job the jobs row — `id`, `type`, `attempts`,
     *        `created_at`, `run_after` — or `[]` when the handler was invoked
     *        outside the queue (a CLI sweep calling it directly).
     */
    public function handle(array $payload, array $job = []): void;
}
