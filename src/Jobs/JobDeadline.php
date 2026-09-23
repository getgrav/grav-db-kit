<?php

declare(strict_types=1);

namespace TrilbyMedia\GravDbKit\Jobs;

/**
 * How long one job is allowed to take.
 *
 * A handler that talks to somebody else's server can block for as long as that
 * server wants it to. Under cron that was survivable — the tick had its own
 * budget and the next one started a minute later — but a daemon holding one
 * hung socket stops being a worker at all, and the inline drain holding one is
 * a visitor watching a spinner.
 *
 * PHP cannot interrupt a blocked call from userland, so there is no honest way
 * to stop a hung handler on every host. There are two mechanisms, and which
 * one a site gets depends on whether pcntl is available:
 *
 * - **Alarm** (`pcntl_alarm`, available on most CLI builds): the signal
 *   arrives while the call is blocked and the handler is stopped where it
 *   stands. This is the real thing.
 * - **After the fact** (everywhere else, including nearly every FastCGI
 *   build): the clock is read when the handler returns. A handler that took
 *   too long is recorded as failed, but nothing stopped it taking that long.
 *
 * `mechanism()` says which one ran so the report, the log line and the docs
 * can be honest about it rather than implying a guarantee that is not there.
 * Both mechanisms mark the job failed, which is what makes the retry and the
 * failed bucket behave the same way on either.
 *
 * Signals only arrive while PHP is executing, and pcntl_signal handlers need
 * ticks or an async-signal-safe build; this asks for async signals with
 * `pcntl_async_signals(true)` so the alarm lands inside a blocking call the
 * way it is meant to.
 */
final class JobDeadline
{
    /** How long a handler may take when the plugin has not said. */
    public const DEFAULT_SECONDS = 120;

    public const MECHANISM_ALARM = 'alarm';
    public const MECHANISM_AFTER_THE_FACT = 'after_the_fact';
    public const MECHANISM_OFF = 'off';

    /** @var callable(): float */
    private $clock;

    private float $startedAt = 0.0;

    private bool $armed = false;

    private bool $tripped = false;

    /** Whether this deadline can interrupt a blocked call; see the constructor. */
    private readonly bool $useAlarm;

    /**
     * @param int $seconds how long a handler may take; 0 or less turns the
     *        deadline off entirely, which is what a site with one very slow
     *        import sets when it would rather wait than retry
     * @param (callable(): float)|null $clock injectable so a test can prove
     *        the after-the-fact path without waiting two minutes
     * @param bool|null $useAlarm which mechanism to use; defaults to whichever
     *        this build can manage. Overridable so the after-the-fact path can
     *        be proven on a machine that has pcntl, and the other way round —
     *        both ship, and a mechanism nobody can test on their own laptop is
     *        a mechanism nobody knows the behaviour of.
     */
    public function __construct(
        private readonly int $seconds,
        ?callable $clock = null,
        ?bool $useAlarm = null,
    ) {
        $this->clock = $clock ?? static fn (): float => microtime(true);
        $this->useAlarm = $useAlarm ?? self::alarmAvailable();
    }

    /**
     * Which mechanism this deadline will actually use.
     *
     * Read before anything is armed, so a command can print it at startup and
     * an operator knows before the first hang whether hangs are survivable on
     * this host.
     */
    public function mechanism(): string
    {
        if ($this->seconds <= 0) {
            return self::MECHANISM_OFF;
        }

        return $this->useAlarm ? self::MECHANISM_ALARM : self::MECHANISM_AFTER_THE_FACT;
    }

    public function seconds(): int
    {
        return $this->seconds;
    }

    /**
     * Start the clock for one job.
     *
     * The alarm throws JobTimedOut from inside the signal handler, so the
     * runner's existing `catch (\Throwable)` marks the job failed with the
     * message and nothing else has to change.
     */
    public function start(): void
    {
        if ($this->seconds <= 0) {
            return;
        }

        $this->startedAt = ($this->clock)();
        $this->armed = true;
        $this->tripped = false;

        if (!$this->useAlarm) {
            return;
        }

        pcntl_async_signals(true);
        pcntl_signal(\SIGALRM, function (): void {
            $this->tripped = true;

            throw new JobTimedOut($this->seconds);
        });
        pcntl_alarm($this->seconds);
    }

    /**
     * Stop the clock and say nothing about it.
     *
     * What a handler that threw on its own gets: its error is the one worth
     * reading, and relabelling it "timed out" because it also happened to be
     * slow would hide the actual cause.
     */
    public function stop(): void
    {
        if (!$this->armed) {
            return;
        }

        $this->armed = false;

        if ($this->useAlarm) {
            pcntl_alarm(0);
            pcntl_signal(\SIGALRM, \SIG_DFL);
        }
    }

    /**
     * Stop the clock after a handler returned, and throw when it overran.
     *
     * The throw only ever comes from the after-the-fact path: where the alarm
     * works, the handler was already stopped mid-call and never got here.
     *
     * @throws JobTimedOut
     */
    public function finish(): void
    {
        if (!$this->armed) {
            return;
        }

        $startedAt = $this->startedAt;
        $this->stop();

        if ($this->useAlarm) {
            return;
        }

        $elapsed = ($this->clock)() - $startedAt;
        if ($elapsed > $this->seconds) {
            $this->tripped = true;

            throw new JobTimedOut($this->seconds, (int)round($elapsed));
        }
    }

    /**
     * Whether the last job run through this deadline passed it.
     *
     * What the daemon reads to decide whether to keep going. A handler that
     * overran once is a handler still holding whatever it was holding —
     * a socket, a file, a lock inside somebody else's library — and PHP has no
     * way to take any of that back. The honest move is to exit and let the
     * supervisor start a process that is definitely clean.
     */
    public function tripped(): bool
    {
        return $this->tripped;
    }

    /** Whether this build can be interrupted mid-call. */
    public static function alarmAvailable(): bool
    {
        return \function_exists('pcntl_alarm')
            && \function_exists('pcntl_signal')
            && \function_exists('pcntl_async_signals');
    }
}
