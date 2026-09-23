<?php

declare(strict_types=1);

namespace TrilbyMedia\GravDbKit\Jobs;

use TrilbyMedia\GravDbKit\Database\Connection;
use TrilbyMedia\GravDbKit\Database\KitTables;
use TrilbyMedia\GravDbKit\Support\Clock;

/**
 * Durable job queue over the kit's jobs table (`KitTables::$jobs`). Claiming
 * is a conditional UPDATE on locked_at IS NULL — atomic on every engine
 * without SELECT FOR UPDATE, so overlapping workers process each job exactly
 * once.
 *
 * The table is made by `InfraTables::jobs()`; its name is validated by
 * KitTables, which is what makes interpolating it below safe.
 */
final class JobQueue
{
    /** The state claim() walks, spelt once. */
    private const RUNNABLE = 'completed_at IS NULL AND cancelled_at IS NULL AND attempts < max_attempts';

    private readonly string $table;

    /** @var (callable(array<string, mixed>): array<string, mixed>)|null */
    private $payloadStamp;

    /** @var callable(): int */
    private $clock;

    /**
     * @param KitTables|null $tables where the jobs table is; `kit_jobs` when
     *        not given
     * @param int $staleLockSeconds how long a claim is trusted. A lock older
     *        than this belongs to a worker that died, and the job is claimable
     *        again.
     * @param (callable(array<string, mixed>): array<string, mixed>)|null $payloadStamp
     *        handed every payload at enqueue time and answers the payload to
     *        store. What a plugin uses to make something about the queueing
     *        request travel with the work — KahunaCart stamps the site URL, so
     *        an email sent by the CLI worker links back to the right host. See
     *        enqueue().
     * @param Clock|(callable(): int)|null $clock the current unix time.
     *        Injectable because the worker has one too, and a test that moves
     *        the worker's clock forward to reach a deferred job has to move the
     *        queue's with it — otherwise the job it is waiting for never comes
     *        due.
     * @param JobTrigger|null $trigger told about each job as it is queued, so
     *        something can run it sooner than the next cron tick. Optional, and
     *        absent everywhere the answer is "cron will get it": a queue with no
     *        trigger behaves exactly as one always has.
     */
    public function __construct(
        private readonly Connection $db,
        ?KitTables $tables = null,
        private readonly int $staleLockSeconds = 300,
        ?callable $payloadStamp = null,
        Clock|callable|null $clock = null,
        private readonly ?JobTrigger $trigger = null,
    ) {
        $this->table = ($tables ?? new KitTables())->jobs;
        $this->payloadStamp = $payloadStamp;
        $this->clock = match (true) {
            $clock instanceof Clock => $clock->now(...),
            $clock !== null => $clock,
            default => static fn (): int => time(),
        };
    }

    /** The queue's own idea of now; see the constructor. */
    public function now(): int
    {
        return ($this->clock)();
    }

    /** The table this queue reads and writes. */
    public function table(): string
    {
        return $this->table;
    }

    /** How long a claim is trusted before the job is handed to somebody else. */
    public function staleLockSeconds(): int
    {
        return $this->staleLockSeconds;
    }

    /**
     * Whether the connection under this queue is inside a transaction.
     *
     * What InlineDrain asks before it runs anything: a request that died with
     * a transaction open still has it open when the shutdown hooks run, and a
     * job it queued inside that transaction would be claimed, run and then
     * rolled back with everything else — an email sent for an order that
     * never existed.
     *
     * Both the connection's own transaction() and one begun on the PDO
     * directly count: a plugin's older code may still do the latter.
     */
    public function inTransaction(): bool
    {
        return $this->db->inTransaction() || $this->db->pdo()->inTransaction();
    }

    /**
     * Queue a job.
     *
     * The payload goes through the payload stamp first, when there is one. The
     * stamp is how the request doing the queueing leaves a note for the worker
     * that runs the job later with no request of its own; KahunaCart's stamps
     * the site URL so absolute links in a CLI-sent email do not say
     * `http://localhost`. The stamp decides whether a caller's own value wins
     * (it should) and whether to add anything when it has nothing to add (it
     * should not — an empty string would look like an answer). A stamp that
     * throws stops the enqueue, before anything is written, so the caller
     * finds out rather than a job running without what it needed.
     *
     * ## The dedupe key
     *
     * `$dedupeKey` is how a caller says "one of these at a time". While a job
     * carrying that key is still waiting to run, enqueueing it again writes
     * nothing and answers the id of the job already there. Once that job has
     * completed — or been cancelled, or spent its last attempt — the key is
     * free and the next call books a fresh one.
     *
     * It exists for recurring work. A sweep that re-books itself for the next
     * interval, and is also booked by whatever noticed there was work to do,
     * turns one job an hour into two and then into four; the guard against that
     * used to be a query every caller had to remember to write. Now it is a
     * word.
     *
     * **It is a read, not a unique index.** Two workers enqueueing the same key
     * in the same instant can both find nothing and both insert. That is a
     * deliberate trade: a unique index would have to be partial — the key has to
     * be reusable the moment the job finishes — and no partial index is spelt
     * the same way on SQLite, MySQL and Postgres. What the read buys is turning
     * "a duplicate every tick" into "a duplicate if two workers land inside the
     * same millisecond", and a site running one worker never sees even that. A
     * handler that genuinely must run once still claims a unique key of its
     * own, which is a constraint and is exact.
     *
     * @param array<string, mixed> $payload
     * @param string|null $dedupeKey at most one unrun job at a time under this
     *        name; null queues unconditionally
     */
    public function enqueue(
        string $type,
        array $payload = [],
        int $runAfter = 0,
        int $maxAttempts = 3,
        ?string $dedupeKey = null,
    ): int {
        $dedupeKey = $dedupeKey === null ? null : trim($dedupeKey);
        if ($dedupeKey === '') {
            $dedupeKey = null;
        }

        if ($dedupeKey !== null) {
            $waiting = $this->unrunJobFor($dedupeKey);
            if ($waiting !== null) {
                return $waiting;
            }
        }

        if ($this->payloadStamp !== null) {
            $payload = ($this->payloadStamp)($payload);
        }

        $row = [
            'type' => $type,
            'payload_json' => json_encode($payload, JSON_THROW_ON_ERROR),
            'run_after' => $runAfter,
            'max_attempts' => $maxAttempts,
            'created_at' => $this->now(),
        ];
        if ($dedupeKey !== null) {
            $row['dedupe_key'] = $dedupeKey;
        }

        $id = $this->db->insert($this->table, $row);

        // The row is already durable, so nothing a trigger does can lose the
        // job and nothing it does may cost the caller their request. A trigger
        // that throws — a broker that went away, an add-on with a bug — gives
        // up the latency it was there to save and nothing else.
        if ($this->trigger !== null) {
            try {
                $this->trigger->wake($id);
            } catch (\Throwable) {
            }
        }

        return $id;
    }

    /**
     * Make a waiting job due no later than `$runAfter`. A job already due sooner, one a worker holds, and one that is finished or cancelled are left alone. Answers whether the job moved.
     *
     * For a deduped job booked for later that a caller now needs sooner: enqueueing again under the key answers the waiting job and changes nothing, so the caller brings it forward with this.
     */
    public function bringForward(int $jobId, int $runAfter): bool
    {
        return $this->db->execute(
            "UPDATE {$this->table} SET run_after = ?
             WHERE id = ?
               AND run_after > ?
               AND locked_at IS NULL
               AND completed_at IS NULL
               AND cancelled_at IS NULL",
            [$runAfter, $jobId, $runAfter]
        ) > 0;
    }

    /**
     * The job holding a dedupe key, or null when nothing holds it.
     *
     * "Holding" is the same set `claim()` will still consider: not completed,
     * not cancelled, and with an attempt left. A job that spent its last attempt
     * is never going to run again, so it does not get to keep a recurring sweep
     * switched off for ever — that would turn one failure into permanent
     * silence, which is the worst way for a sweep to stop.
     *
     * Public because a caller sometimes wants the answer without queueing: the
     * admin showing whether tonight's sweep is already booked, for one.
     */
    public function unrunJobFor(string $dedupeKey): ?int
    {
        $dedupeKey = trim($dedupeKey);
        if ($dedupeKey === '') {
            return null;
        }

        $id = $this->db->fetchValue(
            "SELECT id FROM {$this->table}
             WHERE dedupe_key = ?
               AND " . self::RUNNABLE . '
             ORDER BY id
             LIMIT 1',
            [$dedupeKey]
        );

        return $id === null ? null : (int)$id;
    }

    /**
     * Claim the next runnable job, or null when the queue is drained.
     *
     * `ORDER BY id` is load-bearing rather than incidental: it makes the queue
     * first-in-first-out, which is what lets a caller order two pieces of work
     * by the order it queued them in. A job that mints licence keys and the
     * email carrying those keys, queued in that order, run in that order, and
     * the email's higher id is the guarantee that the keys exist by the time
     * it renders.
     *
     * A lock older than the stale window is a worker that died mid-job, and
     * the job is taken over here like any unclaimed one. The takeover spends
     * an attempt, which is what stops a job that kills its worker every time
     * from being taken over for ever.
     *
     * @return array<string, mixed>|null
     */
    public function claim(string $workerId): ?array
    {
        $now = $this->now();

        // A few candidates per round trip: another worker may win the UPDATE
        // race on the first one.
        $candidates = $this->db->fetchAll(
            "SELECT id FROM {$this->table}
             WHERE " . self::RUNNABLE . '
               AND run_after <= ?
               AND (locked_at IS NULL OR locked_at < ?)
             ORDER BY id
             LIMIT 5',
            [$now, $now - $this->staleLockSeconds]
        );

        foreach ($candidates as $candidate) {
            $id = (int)$candidate['id'];
            $claimed = $this->db->execute(
                "UPDATE {$this->table}
                 SET locked_at = ?, locked_by = ?, attempts = attempts + 1
                 WHERE id = ?
                   AND completed_at IS NULL
                   AND cancelled_at IS NULL
                   AND (locked_at IS NULL OR locked_at < ?)",
                [$now, $workerId, $id, $now - $this->staleLockSeconds]
            );

            if ($claimed === 1) {
                return $this->find($id);
            }
        }

        return null;
    }

    /**
     * Take named keys back out of a job's stored payload.
     *
     * A completed job keeps its row — that is what lets a plugin say what ran
     * and when — so a payload that carried a secret to its handler keeps
     * carrying it long after the handler is done. A gift card's code, say,
     * that exists only in the job that delivers it: the moment the message has
     * left there is no reason for the site to hold the code any longer.
     *
     * A no-op on an unknown job, and on a payload that never had the key. It
     * only ever removes: nothing here can put a value in, so a bug in a caller
     * costs a job its secret rather than rewriting what a job was for.
     *
     * @param list<string> $keys
     */
    public function redact(int $jobId, array $keys): void
    {
        if ($keys === []) {
            return;
        }

        $row = $this->db->fetchRow("SELECT payload_json FROM {$this->table} WHERE id = ?", [$jobId]);
        if ($row === null) {
            return;
        }

        $payload = json_decode((string)($row['payload_json'] ?? ''), true);
        if (!\is_array($payload)) {
            return;
        }

        $before = $payload;
        foreach ($keys as $key) {
            unset($payload[$key]);
        }
        if ($payload === $before) {
            return;
        }

        $this->db->update(
            $this->table,
            ['payload_json' => json_encode($payload, JSON_THROW_ON_ERROR)],
            'id = ?',
            [$jobId]
        );
    }

    /**
     * Where a handler's report is kept inside `payload_json`.
     *
     * Underscore-prefixed because it is the queue's own bookkeeping rather
     * than something the caller who queued the job put there.
     */
    public const RESULT_KEY = '_result';

    /**
     * Record what a job produced, so somebody can read it afterwards.
     *
     * Most jobs need nothing like this: an email either sent or it threw. A
     * bulk action is different — it is one job that did two hundred separate
     * things, ninety-nine of which may have worked — and "it completed" is not
     * an answer anybody can act on. The report says which records changed,
     * which were already in that state, and which failed and why.
     *
     * Written into the job's own `payload_json` rather than into a table of its
     * own. A run's report has exactly the lifetime of the job row it belongs to,
     * it is read by exactly one screen, and a table would need its own
     * migration, its own retention sweep and its own cleanup on a job that was
     * deleted. Read-modify-write because no engine the kit supports can be
     * relied on to patch a JSON key in place. There is no race — a claimed job
     * belongs to one worker, and the only writer is the handler running inside
     * that claim.
     *
     * @param array<string, mixed> $result
     */
    public function attachResult(int $jobId, array $result): void
    {
        $job = $this->db->fetchRow("SELECT payload_json FROM {$this->table} WHERE id = ?", [$jobId]);
        if ($job === null) {
            return;
        }

        $payload = json_decode((string)($job['payload_json'] ?? '{}'), true);
        $payload = \is_array($payload) ? $payload : [];
        $payload[self::RESULT_KEY] = $result;

        $this->db->update(
            $this->table,
            ['payload_json' => json_encode($payload, JSON_THROW_ON_ERROR)],
            'id = ?',
            [$jobId]
        );
    }

    /**
     * A job's report, or null when it has none yet.
     *
     * Null covers "queued and not run", "running", and "a job type that never
     * writes one" — three states a caller treats the same way, by saying the
     * run is not finished.
     *
     * @return array<string, mixed>|null
     */
    public function resultFor(int $jobId): ?array
    {
        $job = $this->db->fetchRow("SELECT payload_json FROM {$this->table} WHERE id = ?", [$jobId]);
        if ($job === null) {
            return null;
        }

        $payload = json_decode((string)($job['payload_json'] ?? '{}'), true);
        $result = \is_array($payload) ? ($payload[self::RESULT_KEY] ?? null) : null;

        return \is_array($result) ? $result : null;
    }

    /**
     * One job row, for a screen polling a run it started.
     *
     * @return array<string, mixed>|null
     */
    public function find(int $jobId): ?array
    {
        return $this->db->fetchRow("SELECT * FROM {$this->table} WHERE id = ?", [$jobId]);
    }

    public function complete(int $jobId): void
    {
        $this->db->update($this->table, [
            'completed_at' => $this->now(),
            'last_error' => null,
        ], 'id = ?', [$jobId]);
    }

    /**
     * Release a failed job for retry with exponential backoff, or leave it
     * locked-out once attempts are exhausted (last_error keeps the cause).
     *
     * The backoff is `min(3600, 2^attempts * 30)` seconds: a minute after the
     * first failure, two after the second, and never more than an hour.
     */
    public function fail(int $jobId, string $error): void
    {
        $job = $this->db->fetchRow("SELECT attempts, max_attempts FROM {$this->table} WHERE id = ?", [$jobId]);
        if ($job === null) {
            return;
        }

        $this->db->update($this->table, [
            'locked_at' => null,
            'locked_by' => null,
            'run_after' => $this->now() + self::backoff((int)$job['attempts']),
            'last_error' => mb_substr($error, 0, 2000),
        ], 'id = ?', [$jobId]);
    }

    /** Seconds a job waits after its `$attempts`th failure. */
    public static function backoff(int $attempts): int
    {
        return min(3600, (2 ** max(0, min($attempts, 20))) * 30);
    }

    /**
     * Hand a claimed job back unrun, to be tried again at $runAfter.
     *
     * This is not fail(): the handler did not fail, it declined to run yet.
     * So there is no `last_error` to write, no exponential backoff — the caller
     * says when — and, crucially, the attempt claim() just consumed is given
     * back. A job that waits ten times for another job to finish must still
     * have all three of its real attempts left if the send then goes wrong.
     *
     * @see DeferJob
     */
    public function defer(int $jobId, int $runAfter): void
    {
        $job = $this->db->fetchRow("SELECT attempts FROM {$this->table} WHERE id = ?", [$jobId]);
        if ($job === null) {
            return;
        }

        $this->db->update($this->table, [
            'locked_at' => null,
            'locked_by' => null,
            'run_after' => $runAfter,
            'attempts' => max(0, (int)$job['attempts'] - 1),
        ], 'id = ?', [$jobId]);
    }

    /**
     * When the soonest job that is not runnable yet becomes runnable, or null
     * when there is no such job.
     *
     * The worker asks this after claim() comes back empty, to tell "the queue
     * is drained" apart from "the queue is holding something for another two
     * seconds". Only jobs in the future are considered: one that is due now and
     * still was not claimed belongs to another worker, and waiting for it would
     * be waiting for nothing.
     */
    public function nextRunAfter(?int $now = null): ?int
    {
        $now ??= $this->now();

        $next = $this->db->fetchValue(
            "SELECT MIN(run_after) FROM {$this->table}
             WHERE " . self::RUNNABLE . '
               AND run_after > ?
               AND (locked_at IS NULL OR locked_at < ?)',
            [$now, $now - $this->staleLockSeconds]
        );

        return $next === null ? null : (int)$next;
    }

    /**
     * How long the oldest runnable job has been waiting, in seconds, or null
     * when nothing is waiting.
     *
     * `created_at`, not `run_after`: a job's run_after moves forward with every
     * retry backoff, so measuring from it would report a queue that has been
     * stuck for a week as thirty seconds behind. What an operator needs to
     * know is how long ago the work was asked for.
     */
    public function oldestPendingAge(?int $now = null): ?int
    {
        $now ??= $this->now();

        $oldest = $this->db->fetchValue(
            "SELECT MIN(created_at) FROM {$this->table} WHERE " . self::RUNNABLE . ' AND run_after <= ?',
            [$now]
        );

        return $oldest === null ? null : max(0, $now - (int)$oldest);
    }

    /**
     * Whether the queue is being drained, in the form an admin screen can show.
     *
     * Nothing here talks to the scheduler — it cannot, a plugin has no way to
     * ask Grav whether cron is wired up — so the queue's own backlog is the
     * evidence. A job that has been runnable for longer than $staleAfterSeconds
     * means nothing is running the worker, and on a site that emails people
     * that is somebody waiting on a message that never comes. The threshold is
     * generous by default: the scheduler ticks every minute, jobs retry with
     * backoff, and a warning that cries wolf gets ignored.
     *
     * `failed` counts jobs that exhausted their attempts. Those are a different
     * problem — the worker ran and the job kept throwing — and they never go
     * away by themselves, so they are worth showing beside the stale count
     * even though they do not make the queue stale.
     *
     * @return array{pending: int, failed: int, oldest_pending_age: int|null, stale: bool, stale_after: int}
     */
    public function health(int $staleAfterSeconds = 900, ?int $now = null): array
    {
        $now ??= $this->now();
        $stats = $this->stats($now);
        $oldest = $this->oldestPendingAge($now);

        return [
            'pending' => $stats['pending'],
            'failed' => $stats['failed'],
            'oldest_pending_age' => $oldest,
            'stale' => $oldest !== null && $oldest >= $staleAfterSeconds,
            'stale_after' => $staleAfterSeconds,
        ];
    }

    /**
     * @return array{pending: int, failed: int, completed: int}
     */
    public function stats(?int $now = null): array
    {
        $now ??= $this->now();

        return [
            'pending' => (int)$this->db->fetchValue(
                "SELECT COUNT(*) FROM {$this->table} WHERE " . self::RUNNABLE . ' AND run_after <= ?',
                [$now]
            ),
            'failed' => (int)$this->db->fetchValue(
                "SELECT COUNT(*) FROM {$this->table} WHERE completed_at IS NULL AND cancelled_at IS NULL AND attempts >= max_attempts"
            ),
            'completed' => (int)$this->db->fetchValue(
                "SELECT COUNT(*) FROM {$this->table} WHERE completed_at IS NOT NULL"
            ),
        ];
    }

    /** The five states the admin and the CLI show a job in. */
    public const STATE_PENDING = 'pending';
    public const STATE_RUNNING = 'running';
    public const STATE_COMPLETE = 'complete';
    public const STATE_FAILED = 'failed';
    public const STATE_CANCELLED = 'cancelled';

    /** @var list<string> */
    public const STATES = [
        self::STATE_PENDING,
        self::STATE_RUNNING,
        self::STATE_COMPLETE,
        self::STATE_FAILED,
        self::STATE_CANCELLED,
    ];

    /** What cancel() answers. */
    public const CANCEL_DONE = 'cancelled';
    public const CANCEL_REQUESTED = 'requested';
    public const CANCEL_TOO_LATE = 'too_late';
    public const CANCEL_UNKNOWN = 'unknown';

    /** Hard ceiling on a page of jobs, whatever the caller asks for. */
    public const MAX_PER_PAGE = 200;

    /**
     * Which of the five states a job row is in, read the way claim() reads it.
     *
     * One place rather than a condition per screen, so the CLI, the admin and
     * the API cannot disagree about whether a job with a stale lock is running
     * or waiting. A lock older than the stale window is a worker that died,
     * and the job is pending again — exactly what claim() will do with it.
     *
     * @param array<string, mixed> $job
     */
    public function stateOf(array $job, ?int $now = null): string
    {
        $now ??= $this->now();

        if (($job['cancelled_at'] ?? null) !== null) {
            return self::STATE_CANCELLED;
        }
        if (($job['completed_at'] ?? null) !== null) {
            return self::STATE_COMPLETE;
        }

        $lockedAt = $job['locked_at'] ?? null;
        if ($lockedAt !== null && (int)$lockedAt >= $now - $this->staleLockSeconds) {
            return self::STATE_RUNNING;
        }

        if ((int)($job['attempts'] ?? 0) >= (int)($job['max_attempts'] ?? 0)) {
            return self::STATE_FAILED;
        }

        return self::STATE_PENDING;
    }

    /**
     * Stop a job.
     *
     * Three answers, because a job is in one of three places when somebody
     * presses Cancel. Not started: it is marked cancelled here and now, and
     * claim() never sees it again. Running: the request is recorded and the
     * runner honours it at its next transaction boundary — nothing is killed
     * mid-write, which is the whole reason this is a flag and not a signal.
     * Already finished: too late, and nothing is changed.
     *
     * A running job whose lock has gone stale is treated as not started: the
     * worker that held it is gone, and the job would otherwise be claimed
     * again by the next tick.
     */
    public function cancel(int $jobId, ?int $now = null): string
    {
        $now ??= $this->now();
        $job = $this->find($jobId);
        if ($job === null) {
            return self::CANCEL_UNKNOWN;
        }

        $state = $this->stateOf($job, $now);
        if ($state === self::STATE_COMPLETE || $state === self::STATE_CANCELLED) {
            return self::CANCEL_TOO_LATE;
        }

        if ($state === self::STATE_RUNNING) {
            $this->db->update($this->table, ['cancel_requested_at' => $now], 'id = ? AND completed_at IS NULL', [$jobId]);

            return self::CANCEL_REQUESTED;
        }

        // Conditional on nothing having claimed it in the meantime: a worker
        // that won the race gets the request instead, and stops at its own
        // boundary.
        $stopped = $this->db->execute(
            "UPDATE {$this->table}
             SET cancelled_at = ?, cancel_requested_at = ?, locked_at = NULL, locked_by = NULL
             WHERE id = ? AND completed_at IS NULL AND cancelled_at IS NULL
               AND (locked_at IS NULL OR locked_at < ?)",
            [$now, $now, $jobId, $now - $this->staleLockSeconds]
        );

        if ($stopped === 1) {
            return self::CANCEL_DONE;
        }

        $this->db->update($this->table, ['cancel_requested_at' => $now], 'id = ? AND completed_at IS NULL', [$jobId]);

        return self::CANCEL_REQUESTED;
    }

    /**
     * Claim one named job rather than the next runnable one.
     *
     * The same conditional UPDATE claim() makes, on one id, for a CLI's
     * `jobs:run` and for the inline drain. A job that is not runnable —
     * complete, cancelled, locked by a live worker, or not yet due — answers
     * null, so running a job by hand can never run it twice.
     *
     * @return array<string, mixed>|null
     */
    public function claimOne(int $jobId, string $workerId, ?int $now = null): ?array
    {
        $now ??= $this->now();

        $claimed = $this->db->execute(
            "UPDATE {$this->table}
             SET locked_at = ?, locked_by = ?, attempts = attempts + 1
             WHERE id = ?
               AND " . self::RUNNABLE . '
               AND run_after <= ?
               AND (locked_at IS NULL OR locked_at < ?)',
            [$now, $workerId, $jobId, $now, $now - $this->staleLockSeconds]
        );

        return $claimed === 1 ? $this->find($jobId) : null;
    }

    /** Has somebody asked for this job to stop? Read by the runner at a boundary. */
    public function cancelRequested(int $jobId): bool
    {
        $value = $this->db->fetchValue("SELECT cancel_requested_at FROM {$this->table} WHERE id = ?", [$jobId]);

        return $value !== null;
    }

    /**
     * Record that a running job stopped at a boundary because it was asked to.
     *
     * The lock is released and the attempt it consumed is given back, for the
     * same reason defer() gives one back: the handler did not fail, it obeyed.
     */
    public function markCancelled(int $jobId, ?int $now = null): void
    {
        $job = $this->db->fetchRow("SELECT attempts FROM {$this->table} WHERE id = ?", [$jobId]);
        if ($job === null) {
            return;
        }

        $this->db->update($this->table, [
            'cancelled_at' => $now ?? $this->now(),
            'locked_at' => null,
            'locked_by' => null,
            'attempts' => max(0, (int)$job['attempts'] - 1),
        ], 'id = ?', [$jobId]);
    }

    /**
     * Let a job run at the next tick, whatever it was waiting for.
     *
     * "Run" on an admin screen means three different things depending on the
     * row it is pressed on, and this does all three: a job waiting on its
     * backoff has the wait removed; a job that exhausted its attempts is given
     * one more, because somebody who has fixed the mail transport wants the
     * mail sent rather than a counter reset by hand in SQL; and a job whose
     * worker died has its stale lock cleared. A job that is complete,
     * cancelled, or genuinely running right now is left alone and the answer
     * is false.
     *
     * The answer is read back off the row rather than taken from the UPDATE's
     * row count, because MySQL counts only rows whose values changed, and a
     * job that was already due now and unlocked changes nothing.
     */
    public function release(int $jobId, ?int $now = null): bool
    {
        $now ??= $this->now();
        $job = $this->find($jobId);
        if ($job === null) {
            return false;
        }

        $state = $this->stateOf($job, $now);
        if (!\in_array($state, [self::STATE_PENDING, self::STATE_FAILED], true)) {
            return false;
        }

        $values = [
            'run_after' => $now,
            'locked_at' => null,
            'locked_by' => null,
        ];
        if ((int)$job['attempts'] >= (int)$job['max_attempts']) {
            $values['max_attempts'] = (int)$job['attempts'] + 1;
        }

        // Conditional on no live worker having claimed it since it was read.
        $this->db->update(
            $this->table,
            $values,
            'id = ? AND completed_at IS NULL AND cancelled_at IS NULL AND (locked_at IS NULL OR locked_at < ?)',
            [$jobId, $now - $this->staleLockSeconds]
        );

        $after = $this->find($jobId);

        return $after !== null
            && $after['locked_at'] === null
            && (int)$after['run_after'] <= $now
            && $this->stateOf($after, $now) === self::STATE_PENDING;
    }

    /**
     * A page of jobs, newest first, optionally narrowed to one state or type.
     *
     * The state filter is applied in PHP over a slightly larger page rather
     * than spelled out in SQL, because "running" and "failed" are both
     * conditions on the stale-lock window and the attempts ceiling, and one
     * definition of them — stateOf() — is worth more than a faster query over a
     * table the retention sweep keeps small.
     *
     * @return array{jobs: list<array<string, mixed>>, total: int}
     */
    public function page(?string $state = null, ?string $type = null, int $limit = 25, int $offset = 0, ?int $now = null): array
    {
        $now ??= $this->now();
        $limit = max(1, min($limit, self::MAX_PER_PAGE));
        $offset = max(0, $offset);

        $where = [];
        $params = [];
        if ($type !== null && $type !== '') {
            $where[] = 'type = ?';
            $params[] = $type;
        }
        $open = \in_array($state, [self::STATE_PENDING, self::STATE_RUNNING, self::STATE_FAILED], true);
        if ($state !== null && $state !== '') {
            $where[] = match ($state) {
                self::STATE_COMPLETE => 'completed_at IS NOT NULL',
                self::STATE_CANCELLED => 'cancelled_at IS NOT NULL',
                // The other three are all "open", and are told apart below.
                default => 'completed_at IS NULL AND cancelled_at IS NULL',
            };
        }
        $sql = $where === [] ? '' : ' WHERE ' . implode(' AND ', $where);

        if ($open) {
            // The open set is what the worker has not got to yet, which on a
            // healthy site is a handful of rows, so reading it whole to tell
            // the three states apart costs nothing.
            $rows = array_values(array_filter(
                $this->db->fetchAll("SELECT * FROM {$this->table}" . $sql . ' ORDER BY id DESC', $params),
                fn (array $row): bool => $this->stateOf($row, $now) === $state
            ));

            return ['jobs' => \array_slice($rows, $offset, $limit), 'total' => \count($rows)];
        }

        // LIMIT/OFFSET are interpolated as clamped integers.
        return [
            'jobs' => $this->db->fetchAll(
                "SELECT * FROM {$this->table}" . $sql . " ORDER BY id DESC LIMIT {$limit} OFFSET {$offset}",
                $params
            ),
            'total' => (int)$this->db->fetchValue("SELECT COUNT(*) FROM {$this->table}" . $sql, $params),
        ];
    }

    /**
     * How many jobs are in each state, for an admin's filter chips.
     *
     * @return array<string, int>
     */
    public function countByState(?int $now = null): array
    {
        $now ??= $this->now();
        $counts = array_fill_keys(self::STATES, 0);

        $counts[self::STATE_COMPLETE] = (int)$this->db->fetchValue(
            "SELECT COUNT(*) FROM {$this->table} WHERE completed_at IS NOT NULL"
        );
        $counts[self::STATE_CANCELLED] = (int)$this->db->fetchValue(
            "SELECT COUNT(*) FROM {$this->table} WHERE cancelled_at IS NOT NULL"
        );

        $open = $this->db->fetchAll(
            "SELECT id, attempts, max_attempts, locked_at, completed_at, cancelled_at
             FROM {$this->table} WHERE completed_at IS NULL AND cancelled_at IS NULL"
        );
        foreach ($open as $row) {
            $counts[$this->stateOf($row, $now)]++;
        }

        return $counts;
    }

    /**
     * Retention for finished jobs.
     *
     * A completed row is kept so an admin can say what ran and when, and a
     * cancelled one so it can say who stopped it; neither is worth keeping for
     * a year. Open jobs — pending, running, failed — are never swept: a failed
     * job is a problem somebody has to look at, and sweeping it is how a
     * problem disappears without being fixed.
     */
    public function purgeFinished(int $days, ?int $now = null): int
    {
        if ($days <= 0) {
            return 0;
        }

        $cutoff = ($now ?? $this->now()) - ($days * 86400);

        return $this->db->delete(
            $this->table,
            '(completed_at IS NOT NULL AND completed_at < ?) OR (cancelled_at IS NOT NULL AND cancelled_at < ?)',
            [$cutoff, $cutoff]
        );
    }
}
