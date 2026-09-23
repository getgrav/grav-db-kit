<?php

declare(strict_types=1);

namespace TrilbyMedia\GravDbKit\Jobs;

/**
 * The jobs a request queued, run by that same request once the response is out.
 *
 * The queue has always been durable and has always been drained a minute at a
 * time, which means an email can sit for up to sixty seconds for no reason
 * other than where in the minute the request landed. This closes that gap on
 * any host, with no new process and no broker: Grav closes the session, hands
 * the response to the client and fires `onShutdown`, and by then PHP is alive
 * with nothing to do and the visitor is already reading the page.
 *
 * It runs the ids this request queued and no others — see PendingDrain — under
 * a small budget, and everything it does not reach is left pending exactly as
 * before. Cron stays wired up as the safety net and nothing here replaces it:
 * this is the fast path, not the only path.
 *
 * ## Two hooks, one drain
 *
 * A plugin wires run() to Grav's `onShutdown` *and* to a plain
 * `register_shutdown_function`, because there are requests on which Grav never
 * fires `onShutdown` (on Grav 2 develop an API request throws inside the
 * session close that comes before it). Whichever hook arrives first drains;
 * every later call, on this instance or any other built over the same
 * PendingDrain, is a no-op. The list is taken from PendingDrain once, and a
 * taken list stays taken.
 *
 * Nothing here relies on anything that may already be torn down when PHP runs
 * its shutdown functions: it reads no Grav service, no session and no config.
 * It holds the runner (and through it the queue and a PDO connection, which
 * PHP keeps alive until after the shutdown functions have run), the pending
 * list and a log callback, and that is all.
 *
 * ## The two hosting cases
 *
 * On FastCGI (php-fpm, nearly every modern host) `fastcgi_finish_request()` has
 * already run by the time this starts, so the client has the whole response and
 * the drain is free — it could take an hour and no visitor would know.
 *
 * On mod_php there is no such call. Grav asks the browser to close the
 * connection with a Content-Length and a `Connection: close`, which most
 * browsers honour, but the PHP process is still the one the request occupies
 * and a slow drain is a slow request as far as the server's worker pool is
 * concerned. That is why the default budget is three seconds rather than
 * thirty, and why the docs say so plainly.
 *
 * ## Nothing may reach the client
 *
 * The body has already gone. Anything printed after it — a handler echoing, a
 * warning with `display_errors` on, a fatal's error page — would be appended to
 * a response the client is already parsing, and on a JSON endpoint that turns a
 * valid reply into a broken one. So the drain runs inside an output buffer with
 * a callback that returns nothing at all, which discards output on the normal
 * path *and* on the path where PHP flushes buffers itself while shutting down
 * after a fatal, and `display_errors` is off for its duration either way. The
 * log line is written from inside the same buffer, so a logger that prints is
 * swallowed too.
 *
 * ## Never inside a transaction
 *
 * A request that died with a transaction open still has it open when the
 * shutdown functions run. A job it queued inside that transaction would be
 * claimed, run — an email sent — and then rolled back with everything else.
 * So a drain that finds the connection inside a transaction runs nothing and
 * leaves the committed jobs, if any, to the worker.
 *
 * ## What a handler may not do
 *
 * Touch the session. Grav closes it before `onShutdown` so the next request can
 * be served, and a handler that reopens it holds the session file for the
 * length of the drain — which is precisely the stall this was built to remove.
 * A handler that tries is left to fail: the job is marked failed with whatever
 * the session threw, the drain carries on with the next job, and the failure is
 * visible in the admin rather than hidden by a workaround.
 */
final class InlineDrain
{
    /** How long a drain runs when the plugin has not said. */
    public const DEFAULT_BUDGET = 3;

    /** A budget above this is refused: no response should wait this long. */
    public const MAX_BUDGET = 30;

    /** @var callable(string): void */
    private $log;

    /**
     * @param PendingDrain $pending the ids this request queued
     * @param JobRunner $runner already carrying its handlers and its per-job
     *        deadline; the drain uses the same deadline the daemon does, so a
     *        hung provider costs a visitor the deadline and not the request
     * @param int $budgetSeconds clamped to MAX_BUDGET
     * @param (callable(string): void)|null $log where the one debug line goes
     * @param bool|null $responseAlreadySent whether the client has the response
     *        already; defaults to asking the host, injectable so a test can
     *        prove both hosting cases on one machine
     */
    public function __construct(
        private readonly PendingDrain $pending,
        private readonly JobRunner $runner,
        private readonly int $budgetSeconds = self::DEFAULT_BUDGET,
        ?callable $log = null,
        private readonly ?bool $responseAlreadySent = null,
    ) {
        $this->log = $log ?? static function (): void {
        };
    }

    /** Whether this host hands the response over before the shutdown work runs. */
    public static function responseIsSentFirst(): bool
    {
        return \function_exists('fastcgi_finish_request');
    }

    /**
     * Run what this request queued, and report what happened.
     *
     * Never throws and never prints. It is called from a shutdown hook, after
     * the response, on behalf of a visitor who has already been served — there
     * is nobody left to tell, and a throw here would only reach the error log
     * by a longer route than the log line below. Safe to call more than once:
     * only the first call does anything.
     *
     * @return array{processed: int, failed: int, deferred: int, skipped: int, jobs: int}
     */
    public function run(): array
    {
        $empty = ['processed' => 0, 'failed' => 0, 'deferred' => 0, 'skipped' => 0, 'jobs' => 0];

        try {
            // Taken first, and only once. A handler is allowed to queue more
            // work, and that work belongs to the next tick — draining onto it
            // here would let one request chase a chain of jobs as long as the
            // chain happened to be. A second shutdown hook finds nothing.
            $taken = $this->pending->take();
        } catch (\Throwable) {
            return $empty;
        }

        if ($taken === null || $taken['ids'] === []) {
            return $empty;
        }

        $ids = $taken['ids'];
        $result = $empty;

        $displayErrors = ini_get('display_errors');
        @ini_set('display_errors', '0');
        // A callback returning an empty string, so the buffer is discarded even
        // when it is PHP rather than this method that ends it. The level is
        // remembered so that whatever a handler does to the buffer stack —
        // closes this buffer, opens three of its own — this method closes
        // exactly what is above the caller's level and nothing below it.
        $level = ob_get_level();
        ob_start(static fn (string $chunk): string => '');

        try {
            $result = $this->drain($ids, $taken['overflowed'], $empty);
        } catch (\Throwable) {
            // drain() already catches everything it can name; this is for the
            // one thing left, a log callback that throws on the way out.
        } finally {
            while (ob_get_level() > $level) {
                if (!@ob_end_clean()) {
                    break;
                }
            }
            if ($displayErrors !== false) {
                @ini_set('display_errors', (string)$displayErrors);
            }
        }

        return $result;
    }

    /**
     * @param list<int> $ids
     * @param array{processed: int, failed: int, deferred: int, skipped: int, jobs: int} $empty
     * @return array{processed: int, failed: int, deferred: int, skipped: int, jobs: int}
     */
    private function drain(array $ids, bool $overflowed, array $empty): array
    {
        $result = $empty;
        $result['jobs'] = \count($ids);

        if ($this->runner->queue()->inTransaction()) {
            $result['skipped'] = \count($ids);
            $this->log(sprintf(
                'the inline job drain ran nothing: the database connection is still inside a transaction, '
                . 'which means the request ended without committing it. %d job(s) left for the worker.',
                \count($ids)
            ));

            return $result;
        }

        $budget = max(1, min($this->budgetSeconds, self::MAX_BUDGET));
        $sentFirst = $this->responseAlreadySent ?? self::responseIsSentFirst();

        $startedAt = microtime(true);
        try {
            $result = $this->runner->runIds($ids, $budget) + ['jobs' => \count($ids)];
        } catch (\Throwable $e) {
            // The runner settles each job itself, so reaching here means
            // something broke between jobs — a lost database connection is the
            // realistic one. The jobs are all still in the table.
            $this->log(sprintf(
                'the inline job drain stopped early — %s: %s',
                $e::class,
                $e->getMessage()
            ));
        }

        $this->log(sprintf(
            'inline job drain ran %d of %d queued job(s) in %.0f ms — %d processed, %d failed, %d deferred, %d left for the worker. '
            . 'The response %s before the drain started (%s), budget %ds.%s',
            $result['processed'] + $result['failed'] + $result['deferred'],
            $result['jobs'],
            (microtime(true) - $startedAt) * 1000,
            $result['processed'],
            $result['failed'],
            $result['deferred'],
            $result['skipped'],
            $sentFirst ? 'had already gone to the client' : 'was still being read by the client',
            $sentFirst ? 'fastcgi_finish_request' : 'no fastcgi_finish_request on this host',
            $budget,
            $overflowed
                ? ' This request queued more than ' . PendingDrain::MAX_IDS . ' jobs, so the rest were left for the worker.'
                : ''
        ));

        return $result;
    }

    private function log(string $message): void
    {
        try {
            ($this->log)($message);
        } catch (\Throwable) {
            // A logger that cannot write is not worth a second failure on a
            // request that is already over.
        }
    }
}
