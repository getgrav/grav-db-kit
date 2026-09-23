<?php

declare(strict_types=1);

namespace TrilbyMedia\GravDbKit\Jobs;

/**
 * The one moment in a worker run recurring work can hang on.
 *
 * A plugin's own maintenance is usually a hardcoded list inside its `work`
 * command — expiry, retention, the sweeps — and without a door into it an
 * add-on with a sweep of its own has two choices and both are bad: keep a
 * clock of its own in a table, or ride the handler-registration event, which
 * fires every time anything builds the registry rather than once a tick.
 *
 * So the worker fires this once per run, carrying the queue and the time the
 * tick believes it is. A listener enqueues its own work with a dedupe key and
 * stops thinking about clocks:
 *
 * ```php
 * $tick->listen(static function (JobQueue $queue, int $now): void {
 *     $queue->enqueue('shipping.poll_tracking', [], 0, 3, 'shipping.poll_tracking');
 * });
 * ```
 *
 * In KahunaCart this was a Grav event, `onKahunaCartWorkerTick`. The kit has
 * no Grav, so listeners are plain callbacks, `onTick(JobQueue, int $now)`, and
 * a plugin that wants its add-ons to join in fires a Grav event of its own
 * from one listener it registers here.
 *
 * Two things worth knowing about when it fires. It is **after** the queue has
 * drained, so work enqueued on it is claimed by the next pass — a minute at
 * most under cron, and about a second under a daemon, which drains
 * continuously. And it is after the plugin's own sweeps, for the same reason
 * add-ons register their job handlers after the owner's: a listener that
 * hangs must not cost the site its own maintenance.
 *
 * **Nothing a listener does can stop the tick.** Each listener is called on its
 * own, and anything it throws is caught and logged before the next one runs,
 * because a worker still holding somebody's confirmation email must not be
 * taken down by somebody else's sweep.
 */
final class WorkerTick
{
    /** @var list<callable(JobQueue, int): void> */
    private array $listeners = [];

    /** @var callable(string): void */
    private $logger;

    /**
     * @param iterable<callable(JobQueue, int): void> $listeners
     * @param (callable(string): void)|null $logger where a listener's failure
     *        is reported
     */
    public function __construct(iterable $listeners = [], ?callable $logger = null)
    {
        foreach ($listeners as $listener) {
            $this->listen($listener);
        }
        $this->logger = $logger ?? static function (): void {
        };
    }

    /**
     * Add a listener, called on every tick after the ones before it.
     *
     * @param callable(JobQueue, int): void $onTick
     */
    public function listen(callable $onTick): self
    {
        $this->listeners[] = $onTick;

        return $this;
    }

    /** How many listeners a tick will call. */
    public function count(): int
    {
        return \count($this->listeners);
    }

    /**
     * Tell every listener that a worker run happened.
     *
     * Never throws.
     *
     * @param int|null $now the time the tick believes it is; the queue's own
     *        clock when not given, which a test can move
     * @return array{now: int, listeners: int, failed: int}
     */
    public function fire(JobQueue $queue, ?int $now = null): array
    {
        $now ??= $queue->now();
        $failed = 0;

        foreach ($this->listeners as $listener) {
            try {
                $listener($queue, $now);
            } catch (\Throwable $e) {
                $failed++;
                try {
                    ($this->logger)('a listener failed on the worker tick — ' . $e::class . ': ' . $e->getMessage());
                } catch (\Throwable) {
                }
            }
        }

        return ['now' => $now, 'listeners' => \count($this->listeners), 'failed' => $failed];
    }
}
