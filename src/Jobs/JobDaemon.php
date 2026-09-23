<?php

declare(strict_types=1);

namespace TrilbyMedia\GravDbKit\Jobs;

use TrilbyMedia\GravDbKit\Support\Clock;

/**
 * The worker as a process that stays up, for a site that can run one.
 *
 * Cron's floor is a minute, and a minute is a long time to hold somebody's
 * email. A daemon polls every second instead, so the queue is drained about
 * as fast as it is filled. It is the second of the two fast paths the kit
 * offers — the other is the inline drain, which needs no process at all — and
 * neither replaces cron, which stays wired up as the safety net for everything
 * a visitor's request and a running daemon both missed.
 *
 * ## It is built to die
 *
 * A long-lived PHP process is not a thing to trust. Extensions leak, providers
 * hang, a database connection goes away and cannot be got back honestly, and
 * memory climbs for reasons nobody wants to spend an afternoon on. So this does
 * not try to run forever: it runs until one of five things is true, tidies up,
 * and exits — max jobs, max time, a memory ceiling, a job that passed its
 * deadline, or a SIGTERM from the supervisor. Restarting is the supervisor's
 * job, which is what `Restart=always` in the unit file is for, and a process
 * that has just started is the only process anybody can vouch for.
 *
 * Exit code 0 means "I stopped because you told me to, or because I reached a
 * limit you set" — nothing is wrong, start me again. Exit code 1 means "I
 * stopped because something is wrong here": a job hung, or the database went
 * away. A supervisor restarts both; the difference is what an operator reads in
 * the log.
 *
 * ## Job by job, not tick by tick
 *
 * A plugin's cron `work` command gives {@see JobRunner::run()} a budget and
 * lets it drain for fifty seconds. The daemon claims one job at a time
 * instead, so the memory ceiling can be checked after every job rather than
 * after every fifty, a SIGTERM is honoured within one job rather than within a
 * tick, and an empty queue is re-polled a second later rather than slept on
 * for ten. The jobs themselves go through exactly the same `execute()`.
 *
 * ## A lost database connection is fatal
 *
 * On purpose, and it is the one design choice here somebody will ask about.
 * Reconnecting sounds kind and is not: the connection went away in the middle
 * of a job whose row is claimed, `locked_at` is set and `attempts` is already
 * incremented, and a reconnecting process would carry on with no way of knowing
 * what the last statement did. Exiting hands the job to the stale-lock window,
 * which is the mechanism built for exactly this, and hands the process to the
 * supervisor, which is the mechanism built for exactly that.
 */
final class JobDaemon
{
    /** How long the loop waits after finding nothing to do. */
    public const DEFAULT_POLL = 1;

    /** Jobs one process will do before handing over to a fresh one. */
    public const DEFAULT_MAX_JOBS = 500;

    /** Seconds one process will live. An hour, so a leak is bounded by an hour. */
    public const DEFAULT_MAX_TIME = 3600;

    /** Fraction of `memory_limit` at which the process stands down. */
    public const DEFAULT_MAX_MEMORY = 0.8;

    /** How often the sweeps the tick does run inside the loop. */
    public const DEFAULT_HOUSEKEEPING = 60;

    public const STOP_MAX_JOBS = 'max_jobs';
    public const STOP_MAX_TIME = 'max_time';
    public const STOP_MEMORY = 'memory';
    public const STOP_SIGNAL = 'signal';
    public const STOP_JOB_TIMEOUT = 'job_timeout';
    public const STOP_DATABASE = 'database';

    /** @var callable(): int */
    private $clock;

    /** @var callable(int): void */
    private $sleep;

    /** @var callable(): int */
    private $memory;

    /** @var (callable(): void)|null */
    private $housekeeping;

    /** @var callable(string): void */
    private $log;

    private bool $stopping = false;

    /**
     * @param JobRunner $runner already carrying its handlers and its deadline
     * @param DaemonHeartbeat|null $heartbeat where the loop records that it is
     *        alive; null records nothing
     * @param array{poll?: int, max_jobs?: int, max_time?: int, max_memory?: float, housekeeping?: int} $options
     * @param (callable(): void)|null $housekeeping the sweeps a cron tick does
     *        after its jobs — retention, expiry, a WorkerTick::fire(). Run on a
     *        timer inside the loop rather than per job, because they are a
     *        minute's worth of tidying and running them after every email would
     *        be the whole point of a daemon spent on housekeeping.
     * @param (callable(string): void)|null $log where a line an operator should
     *        read goes
     * @param Clock|(callable(): int)|null $clock unix time; injectable so a
     *        test can reach a one-hour limit without waiting an hour. Defaults
     *        to the queue's own clock.
     * @param (callable(int): void)|null $sleep how to wait $seconds
     * @param (callable(): int)|null $memory bytes in use; injectable so a test
     *        can cross the ceiling without allocating anything
     * @param int $memoryLimitBytes the ceiling the fraction is taken of; -1
     *        when PHP has no limit, in which case there is nothing to cross.
     *        phpMemoryLimit() reads it from `memory_limit`.
     */
    public function __construct(
        private readonly JobQueue $queue,
        private readonly JobRunner $runner,
        private readonly string $workerId,
        private readonly ?DaemonHeartbeat $heartbeat = null,
        private readonly array $options = [],
        ?callable $housekeeping = null,
        ?callable $log = null,
        Clock|callable|null $clock = null,
        ?callable $sleep = null,
        ?callable $memory = null,
        private readonly int $memoryLimitBytes = -1,
    ) {
        $this->housekeeping = $housekeeping;
        $this->log = $log ?? static function (): void {
        };
        $this->clock = match (true) {
            $clock instanceof Clock => $clock->now(...),
            $clock !== null => $clock,
            default => $queue->now(...),
        };
        $this->sleep = $sleep ?? static function (int $seconds): void {
            sleep($seconds);
        };
        $this->memory = $memory ?? static fn (): int => memory_get_usage(true);
    }

    /**
     * Run until something says stop, and say what did.
     *
     * @return array{stop: string, exit_code: int, processed: int, failed: int, deferred: int, jobs: int, message: string}
     */
    public function run(): array
    {
        $poll = max(0, (int)($this->options['poll'] ?? self::DEFAULT_POLL));
        $maxJobs = max(0, (int)($this->options['max_jobs'] ?? self::DEFAULT_MAX_JOBS));
        $maxTime = max(0, (int)($this->options['max_time'] ?? self::DEFAULT_MAX_TIME));
        $maxMemory = (float)($this->options['max_memory'] ?? self::DEFAULT_MAX_MEMORY);
        $housekeepEvery = max(0, (int)($this->options['housekeeping'] ?? self::DEFAULT_HOUSEKEEPING));

        $startedAt = ($this->clock)();
        $ceiling = $this->memoryCeiling($maxMemory);

        $processed = 0;
        $failed = 0;
        $deferred = 0;
        $jobs = 0;

        $this->listenForSignals();
        $this->heartbeat?->beat($startedAt, 0, $startedAt, ($this->memory)(), true);

        // Once at the start, so a daemon on a site with no cron at all still
        // does yesterday's sweeps before it does anything else.
        $lastHousekeeping = $this->housekeep(0, $housekeepEvery, $startedAt) ? $startedAt : 0;

        $stop = self::STOP_SIGNAL;
        $message = '';

        while (true) {
            if ($this->stopping) {
                $stop = self::STOP_SIGNAL;
                $message = 'A stop signal arrived; the current job finished first.';

                break;
            }

            $now = ($this->clock)();
            if ($maxTime > 0 && $now - $startedAt >= $maxTime) {
                $stop = self::STOP_MAX_TIME;
                $message = sprintf('Reached max_time (%ds).', $maxTime);

                break;
            }

            try {
                $job = $this->queue->claim($this->workerId);
            } catch (\Throwable $e) {
                return $this->stopped(self::STOP_DATABASE, 1, $processed, $failed, $deferred, $jobs, sprintf(
                    'The database went away while claiming a job (%s: %s). Exiting so the supervisor starts a clean process; '
                    . 'anything claimed is released by the stale-lock window.',
                    $e::class,
                    $e->getMessage()
                ));
            }

            if ($job === null) {
                $lastHousekeeping = $this->housekeep($lastHousekeeping, $housekeepEvery, $now) ? $now : $lastHousekeeping;
                $this->heartbeat?->beat($startedAt, $jobs, $now, ($this->memory)());

                if ($poll > 0) {
                    ($this->sleep)($poll);
                }

                continue;
            }

            try {
                $outcome = $this->runner->execute($job);
            } catch (\Throwable $e) {
                // execute() settles the row itself and swallows anything the
                // handler threw, so reaching here means the settling failed —
                // which in practice means the connection is gone.
                return $this->stopped(self::STOP_DATABASE, 1, $processed, $failed, $deferred, $jobs, sprintf(
                    'The database went away while finishing job #%d (%s: %s). Exiting so the supervisor starts a clean process.',
                    (int)$job['id'],
                    $e::class,
                    $e->getMessage()
                ));
            }

            $jobs++;
            match ($outcome) {
                JobRunner::OUTCOME_PROCESSED => $processed++,
                JobRunner::OUTCOME_DEFERRED => $deferred++,
                JobRunner::OUTCOME_CANCELLED => null,
                default => $failed++,
            };

            $now = ($this->clock)();
            $this->heartbeat?->beat($startedAt, $jobs, $now, ($this->memory)());

            // Between jobs, not inside one: a handler that built a big object
            // graph has dropped it by now, and collecting here is what keeps a
            // five-hundred-job process from ending on the memory ceiling.
            gc_collect_cycles();

            // A job that passed its deadline is a job still holding whatever it
            // was holding — PHP cannot take a socket back off a handler that
            // never returned. See the class docblock.
            $deadline = $this->runner->deadline();
            if ($deadline !== null && $deadline->tripped()) {
                return $this->stopped(self::STOP_JOB_TIMEOUT, 1, $processed, $failed, $deferred, $jobs, sprintf(
                    'Job #%d (%s) passed its %ds deadline and was marked failed. Exiting so the supervisor starts a clean process.',
                    (int)$job['id'],
                    (string)$job['type'],
                    $deadline->seconds()
                ));
            }

            $inUse = ($this->memory)();
            if ($ceiling !== null && $inUse >= $ceiling) {
                $stop = self::STOP_MEMORY;
                $message = sprintf(
                    'Memory reached %s of the %s limit (max_memory %.2f). Standing down for a fresh process.',
                    self::bytes($inUse),
                    self::bytes($this->memoryLimitBytes),
                    $maxMemory
                );

                break;
            }

            if ($maxJobs > 0 && $jobs >= $maxJobs) {
                $stop = self::STOP_MAX_JOBS;
                $message = sprintf('Reached max_jobs (%d).', $maxJobs);

                break;
            }

            $lastHousekeeping = $this->housekeep($lastHousekeeping, $housekeepEvery, $now) ? $now : $lastHousekeeping;
        }

        return $this->stopped($stop, 0, $processed, $failed, $deferred, $jobs, $message);
    }

    /**
     * Whether this process is winding up.
     *
     * Read by nothing here — the loop reads the property — and public so a test
     * can prove that a signal was noticed.
     */
    public function stopping(): bool
    {
        return $this->stopping;
    }

    /** Ask the loop to finish the job it is on and stop. */
    public function stop(): void
    {
        $this->stopping = true;
    }

    /**
     * The last thing that happens, whichever way the loop ended.
     *
     * A clean exit clears the heartbeat so nothing warns about a daemon that
     * was stopped on purpose; a failed one leaves the last beat behind, which is
     * what a status screen's warning is made of.
     *
     * @return array{stop: string, exit_code: int, processed: int, failed: int, deferred: int, jobs: int, message: string}
     */
    private function stopped(
        string $stop,
        int $exitCode,
        int $processed,
        int $failed,
        int $deferred,
        int $jobs,
        string $message,
    ): array {
        if ($exitCode === 0) {
            $this->heartbeat?->clear();
        } else {
            $this->log('worker daemon stopping — ' . $message);
        }

        $this->stopListening();

        return [
            'stop' => $stop,
            'exit_code' => $exitCode,
            'processed' => $processed,
            'failed' => $failed,
            'deferred' => $deferred,
            'jobs' => $jobs,
            'message' => $message,
        ];
    }

    /** Run the sweeps if they are due, and say whether they ran. */
    private function housekeep(int $lastRunAt, int $every, int $now): bool
    {
        if ($this->housekeeping === null || $every <= 0 || $now - $lastRunAt < $every) {
            return false;
        }

        try {
            ($this->housekeeping)();
        } catch (\Throwable $e) {
            // The sweeps are tidying. None of them is worth taking a worker
            // down for while there are people's emails in the queue.
            $this->log('worker daemon housekeeping failed — ' . $e::class . ': ' . $e->getMessage());
        }

        return true;
    }

    /** The byte count at which the loop stands down, or null when there is none. */
    private function memoryCeiling(float $fraction): ?int
    {
        if ($this->memoryLimitBytes <= 0 || $fraction <= 0.0) {
            return null;
        }

        return (int)($this->memoryLimitBytes * min(1.0, $fraction));
    }

    /**
     * Notice SIGTERM and SIGINT, where the host lets us.
     *
     * A supervisor stopping a worker sends SIGTERM and then waits; the polite
     * answer is to finish the job in hand and go, rather than being killed
     * half-way through a send with the row still claimed. Where pcntl is
     * missing there is nothing to install and a stop is a kill, which the
     * stale-lock window covers.
     */
    private function listenForSignals(): void
    {
        if (!\function_exists('pcntl_signal') || !\function_exists('pcntl_async_signals')) {
            return;
        }

        pcntl_async_signals(true);
        $stop = function (): void {
            $this->stopping = true;
        };

        pcntl_signal(\SIGTERM, $stop);
        pcntl_signal(\SIGINT, $stop);
    }

    private function stopListening(): void
    {
        if (!\function_exists('pcntl_signal')) {
            return;
        }

        pcntl_signal(\SIGTERM, \SIG_DFL);
        pcntl_signal(\SIGINT, \SIG_DFL);
    }

    private function log(string $message): void
    {
        try {
            ($this->log)($message);
        } catch (\Throwable) {
            // The loop's own exit is what matters; a logger that cannot write
            // must not turn a clean stop into a crash.
        }
    }

    /**
     * What PHP will let this process allocate, in bytes, or -1 for no limit.
     *
     * The usual `$memoryLimitBytes`: a daemon standing down at eighty per cent
     * of it exits on its own terms rather than on PHP's.
     */
    public static function phpMemoryLimit(): int
    {
        $limit = trim((string)ini_get('memory_limit'));
        if ($limit === '' || $limit === '-1') {
            return -1;
        }

        $units = ['k' => 1024, 'm' => 1024 ** 2, 'g' => 1024 ** 3];
        $suffix = strtolower(substr($limit, -1));

        return (int)$limit * ($units[$suffix] ?? 1);
    }

    /** A byte count an operator can read at a glance in a log line. */
    private static function bytes(int $bytes): string
    {
        if ($bytes < 1024) {
            return $bytes . ' B';
        }

        $units = ['KB', 'MB', 'GB'];
        $value = $bytes / 1024;
        $unit = 0;
        while ($value >= 1024 && $unit < \count($units) - 1) {
            $value /= 1024;
            $unit++;
        }

        return round($value, 1) . ' ' . $units[$unit];
    }
}
