<?php

declare(strict_types=1);

namespace TrilbyMedia\GravDbKit\Jobs;

/**
 * The jobs this one request queued, remembered until the response has gone.
 *
 * A request that does something real queues a few jobs and then finishes the
 * page. Grav closes the session, hands the response to the client, and fires
 * `onShutdown` — and at that point the visitor is already reading the page
 * while PHP is still alive with nothing left to do. Running the jobs there
 * costs the visitor nothing and saves them the wait for the next cron tick,
 * which on a bad minute is the whole minute.
 *
 * Only the ids collected here are ever run. The rest of the queue is left
 * alone: a request pays for its own work and not for the backlog, so a site
 * with two thousand stuck jobs does not hand the bill to the next visitor who
 * clicks a button.
 *
 * An instance per plugin service container rather than a static list,
 * because a static one is shared with every test in the same process and with
 * anything else that happens to run in-process — and a list of job ids that
 * outlives the request that made it is a list of jobs somebody else's request
 * will run.
 *
 * It also remembers that the drain has had its turn. A plugin wires the drain
 * to more than one shutdown hook (see InlineDrain), and the second hook must
 * find nothing to do — including nothing a handler queued during the first.
 *
 * @see InlineDrain for what happens to the ids
 */
final class PendingDrain implements JobTrigger
{
    /**
     * How many ids one request will remember.
     *
     * A request queueing more than this is doing something a request should
     * not — a bulk import, a full reindex — and those belong to the worker, not
     * to the visitor waiting on the response. Past the cap the ids are dropped
     * rather than kept, so nothing here can grow without bound; the jobs
     * themselves are in the table and cron will get them.
     */
    public const MAX_IDS = 100;

    /** @var list<int> */
    private array $ids = [];

    private bool $overflowed = false;

    private bool $drained = false;

    public function wake(int $jobId): void
    {
        // Work queued after the drain started — by a handler inside it, or by
        // a shutdown listener after it — belongs to the next tick.
        if ($this->drained) {
            return;
        }

        if (\count($this->ids) >= self::MAX_IDS) {
            $this->overflowed = true;

            return;
        }

        $this->ids[] = $jobId;
    }

    /**
     * The ids in the order they were queued.
     *
     * The order is load-bearing for the same reason `claim()` orders by id: a
     * site queues a job that mints licence keys and then the email that
     * carries them, and running them out of order sends an empty email.
     *
     * @return list<int>
     */
    public function ids(): array
    {
        return $this->ids;
    }

    public function isEmpty(): bool
    {
        return $this->ids === [];
    }

    /** Whether this request queued more jobs than one request will drain. */
    public function overflowed(): bool
    {
        return $this->overflowed;
    }

    /**
     * Forget everything collected so far.
     *
     * Called once the drain has had its turn, and by a plugin whose inline
     * drain is switched off, so the list never outlives the request.
     */
    public function clear(): void
    {
        $this->ids = [];
        $this->overflowed = false;
    }

    /**
     * Hand the collected ids over, once.
     *
     * Answers the ids and forgets them, and from then on answers nothing and
     * collects nothing: whichever shutdown hook reaches the drain first gets
     * the list, and every later one finds it empty. That is what makes the
     * drain safe to wire to both Grav's `onShutdown` and a plain
     * `register_shutdown_function`.
     *
     * @return array{ids: list<int>, overflowed: bool}|null null when the list
     *         was already taken
     */
    public function take(): ?array
    {
        if ($this->drained) {
            return null;
        }

        $this->drained = true;
        $taken = ['ids' => $this->ids, 'overflowed' => $this->overflowed];
        $this->clear();

        return $taken;
    }

    /** Whether the drain has already taken this request's list. */
    public function drained(): bool
    {
        return $this->drained;
    }
}
