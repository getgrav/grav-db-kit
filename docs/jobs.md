# Jobs: queue, runner, worker, inline drain

The `TrilbyMedia\GravDbKit\Jobs` namespace is KahunaCart's job system with the Grav parts taken out. A plugin gets a durable queue in its own database, a runner that drains it within a time budget, a daemon for sites that can keep a process up, and an inline drain that runs the jobs a request queued right after the response has gone. Cron stays the safety net underneath all of it.

Nothing here reads Grav config, fires Grav events, touches `$grav[...]` or writes to Grav's logger. Everything that needs Grav is a callable or an interface the plugin passes in, so this page shows the Grav side as pseudocode for the plugin to write.

## The pieces

| Class | What it does |
|---|---|
| `JobQueue` | The queue over `KitTables::$jobs`. Enqueue with an optional dedupe key, claim (atomic conditional UPDATE, first in first out), complete, fail with backoff `min(3600, 2^attempts * 30)`, defer, cancel, release, stale lock takeover, `health()`, `stats()`, `page()`, `countByState()`, `purgeFinished()`, `attachResult()`, `redact()`, `bringForward()`. |
| `JobHandler` | `handle(array $payload): void`. Throw to fail and retry. |
| `ContextualJobHandler` | `handle(array $payload, array $job = [])`, for a handler that needs its own row (`id`, `created_at`, `attempts`). |
| `JobHandlerRegistry` | Collects handlers by type. First registration wins, malformed types are dropped, nothing throws. |
| `JobRunner` | `run()` drains for a budget, `runIds()` runs a given list (the inline drain), `runOne()` runs one job by hand, `execute()` settles one claimed row. |
| `DeferJob` | Thrown by a handler to say "not yet": the job goes back with its attempt returned and no error written. |
| `JobCancellation`, `JobCancelled` | `JobCancellation::checkpoint($queue, $job)` between transactions stops a job somebody cancelled, at a boundary. |
| `JobDeadline`, `JobTimedOut` | Per-job time limit: a `pcntl_alarm` where the build has pcntl, a look at the clock afterwards everywhere else. |
| `JobTrigger` | Told about each new job id as it is queued. |
| `PendingDrain` | The `JobTrigger` that remembers the ids one request queued, up to 100, and hands them over once. |
| `InlineDrain` | Runs a request's own jobs after the response, within a small budget, never throwing and never printing. |
| `JobDaemon` | The worker as a long-running process that is built to die: max jobs, max time, memory ceiling, a job past its deadline, SIGTERM, or a lost database. |
| `DaemonHeartbeat` | Interface the daemon writes its "still alive" beat through. The plugin decides where it lives. |
| `WorkerTick` | Callbacks `onTick(JobQueue $queue, int $now)` fired once per worker run, for recurring work. |

## The table

The queue's table comes from `InfraTables::jobs()` in the plugin's first migration, under whatever name `KitTables` gives it:

```php
public function steps(Dialect $d): array
{
    $t = KitTables::withPrefix('helpdesk');   // helpdesk_jobs

    return [
        'create_helpdesk_jobs' => InfraTables::jobs($d, $t),
        // ...
    ];
}
```

The same step brings an older queue table forward in place (Forum Pro's `forum_jobs` gains `cancel_requested_at`, `cancelled_at` and `dedupe_key`), so a plugin that switches can append it to its existing migrations.

## Wiring it into a Grav plugin

The plugin owns one service container (a class it already has, like `KahunaCart` or Helpdesk Pro's container) and builds the job objects there once per request. In pseudocode:

```php
final class Services
{
    private ?PendingDrain $pending = null;
    private ?JobQueue $jobs = null;

    public function tables(): KitTables
    {
        return KitTables::withPrefix('helpdesk');
    }

    // Collected on every web request, never under the CLI: a worker has a
    // loop to run follow-up jobs in and no shutdown to run them at.
    public function pendingDrain(): PendingDrain
    {
        return $this->pending ??= new PendingDrain();
    }

    public function jobs(): JobQueue
    {
        return $this->jobs ??= new JobQueue(
            $this->connection(),
            $this->tables(),
            staleLockSeconds: 300,
            payloadStamp: $this->payloadStamp(),     // optional, see below
            trigger: PHP_SAPI === 'cli' ? null : $this->pendingDrain(),
        );
    }

    public function handlers(): JobHandlerRegistry
    {
        $registry = new JobHandlerRegistry(fn (string $line) => $grav['log']->error('helpdesk-pro: ' . $line));

        // The plugin's own types first, so no add-on can take one over.
        $registry->register('mail.send', new MailSend(...));
        $registry->register('notify.deliver', new NotifyDeliver(...));

        // Then the add-ons, through an event of the plugin's own.
        $grav->fireEvent('onHelpdeskRegisterJobHandlers', new Event(['registry' => $registry]));

        return $registry;
    }

    public function runner(): JobRunner
    {
        return (new JobRunner($this->jobs(), JobRunner::hostWorkerId()))
            ->registerAll($this->handlers())
            ->withDeadline(new JobDeadline((int)$config->get('plugins.helpdesk-pro.jobs.job_timeout_seconds', JobDeadline::DEFAULT_SECONDS)));
    }
}
```

Anything a request wants done later goes through `$services->jobs()->enqueue('mail.send', ['limit' => 50])`. The job is in the table before `enqueue()` returns, the trigger is told, and nothing a trigger does can fail the request.

### The payload stamp

KahunaCart stamped the site URL into every payload so an email sent by the CLI worker links to the host the visitor was on. In the kit that is a callable the plugin passes as `payloadStamp`: it is handed the payload at enqueue time and returns the payload to store. KahunaCart's rule, written as a stamp:

```php
payloadStamp: static function (array $payload): array {
    if (!isset($payload[SiteUrl::PAYLOAD_KEY])) {
        $url = SiteUrl::capture();          // '' under the CLI
        if ($url !== '') {
            $payload[SiteUrl::PAYLOAD_KEY] = $url;
        }
    }

    return $payload;
},
```

The stamp decides whether a caller's own value wins (it should) and whether to add anything when it has nothing to add (it should not). A stamp that throws stops the enqueue before anything is written.

## Writing handlers

A handler is a class with `handle(array $payload): void`. Throw and the job fails, keeps the attempt it spent, and retries after the backoff; after `max_attempts` (3 by default) it stays in the failed bucket with `last_error` set until somebody releases it. Handlers must be idempotent, because a worker can die between the work and the completion write and the stale-lock window then hands the job to another worker.

- **Not yet.** `throw new DeferJob(5)` hands the job back to run five seconds later, with its attempt returned and nothing in `last_error`. The cron pass waits for it (up to ten seconds at a time, within its budget), so a job waiting on another job usually still finishes in the same pass.
- **Needing the row.** Implement `ContextualJobHandler` and the runner passes the claimed row as the second argument.
- **Honouring Cancel.** A handler that does many things, one transaction each, calls `JobCancellation::checkpoint($queue, $job)` between them. Cancelling a running job only sets a flag; the handler stops at its next checkpoint and the runner marks the job cancelled with its attempt returned. A handler that never asks finishes.
- **Reporting.** A bulk job can write a report onto its own row with `$queue->attachResult($id, [...])`; a screen reads it back with `resultFor($id)`.
- **Secrets.** A payload that carried a secret (a gift card code) can have it taken back out once the job is done with `$queue->redact($id, ['code'])`.

### Recurring work and dedupe keys

A job that must repeat books its next run before returning, with a dedupe key:

```php
$queue->enqueue('imap.poll', ['mailbox' => $id], $now + $interval, 3, 'imap.poll:' . $id);
```

While a job with that key is still waiting (not completed, not cancelled, attempts left), enqueueing it again writes nothing and returns the waiting job's id; once it has run, the key is free. `unrunJobFor($key)` answers the same question without queueing, and `bringForward($id, $runAfter)` moves a waiting job earlier (never later) when a caller needs a booked run sooner. The key is a read, not a unique index, so two workers enqueueing the same key in the same millisecond can both insert; a handler that must run exactly once still needs a unique constraint of its own.

### The worker tick

`WorkerTick` is where recurring work gets re-booked when nothing else booked it. KahunaCart fired a Grav event for it; the kit calls callbacks:

```php
$tick = new WorkerTick([], fn (string $line) => $grav['log']->error('helpdesk-pro: ' . $line));
$tick->listen(fn (JobQueue $queue, int $now) => Maintenance::ensureRecurring($queue, $now));
// Let add-ons join in through the plugin's own event, from one listener:
$tick->listen(fn (JobQueue $queue, int $now) => $grav->fireEvent('onHelpdeskWorkerTick', new Event(['queue' => $queue, 'now' => $now])));

$tick->fire($services->jobs());
```

Each listener runs on its own; one that throws is logged and the next still runs. `fire()` never throws.

## The worker command

The plugin's CLI command is the cron worker, `bin/plugin helpdesk-pro work`:

```php
protected function serve(): int
{
    $services = HelpdeskPro::services();
    $queue = $services->jobs();
    $runner = $services->runner();

    if ($this->input->getOption('daemon')) {
        return $this->runDaemon($services, $runner);
    }

    // Under a minute, so two cron ticks never pile up.
    $result = $runner->run(50);

    $this->housekeeping($services);        // retention, expiry, the tick

    $this->getIO()->writeln(sprintf('%d processed, %d failed, %d deferred', ...$result));

    return 0;
}

private function housekeeping(Services $services): void
{
    $services->jobs()->purgeFinished((int)$config->get('plugins.helpdesk-pro.jobs.retention_days', 30));
    // ... the plugin's own sweeps ...
    $services->tick()->fire($services->jobs());   // last, after the plugin's own work
}
```

`$runner->run(50, 50)` caps a pass at fifty jobs as well, which is what Forum Pro's worker did. `$runner->runOne($id)` is the body of a `jobs:run <id>` command: it releases the job (clearing a backoff, a stale lock, or granting a failed job one more attempt) and runs it through the same code path the worker uses.

### The daemon

`work --daemon` keeps one process up and claims one job at a time, so a queued job waits about a second rather than up to a minute:

```php
private function runDaemon(Services $services, JobRunner $runner): int
{
    $daemon = new JobDaemon(
        $services->jobs(),
        $runner,
        JobRunner::hostWorkerId(),
        new KvHeartbeat($services->kv()),                 // the plugin's DaemonHeartbeat
        [
            'poll' => 1,
            'housekeeping' => 60,
            'max_jobs' => (int)$this->input->getOption('max-jobs'),     // 500
            'max_time' => (int)$this->input->getOption('max-time'),     // 3600
            'max_memory' => (float)$this->input->getOption('max-memory'), // 0.8
        ],
        housekeeping: fn () => $this->housekeeping($services),
        log: fn (string $line) => $grav['log']->warning('helpdesk-pro: ' . $line),
        memoryLimitBytes: JobDaemon::phpMemoryLimit(),
    );

    $result = $daemon->run();

    return $result['exit_code'];   // 0: a limit or SIGTERM; 1: a hung job or a lost database
}
```

The daemon is meant to be restarted by systemd (`Restart=always`) or supervisor. It exits on purpose rather than trying to live for ever, and exit code 1 is what an operator should read about. A lost database connection is fatal by design: the claimed job is released by the stale-lock window. `DaemonHeartbeat` is an interface; a plugin implements `beat()` (throttled, never throwing) and `clear()` over its KV table or settings table, and its status screen reads the beat back. `$runner->deadline()?->mechanism()` says whether hangs are stopped by an alarm or only noticed afterwards, which is worth printing when the daemon starts.

## The scheduler

Cron is the path that always works. The plugin adds its worker to Grav's scheduler:

```php
public function onSchedulerInitialized(Event $event): void
{
    $event['scheduler']->addCommand('bin/plugin', ['helpdesk-pro', 'work'], 'helpdesk-pro-jobs')->at('* * * * *');
}
```

and the site runs `bin/grav scheduler` every minute from cron. The trap to repeat in every plugin's docs: `bin/plugin` starts with `#!/usr/bin/env php`, and Grav's scheduler runs it as a child process that inherits cron's `PATH`. An absolute PHP binary in the crontab line fixes the parent and not the child, so the scheduler looks healthy while every job fails with `env: php: No such file or directory`. The crontab needs a `PATH=` line naming the directory PHP lives in:

```
PATH=/opt/homebrew/opt/php@8.4/bin:/usr/bin:/bin:/usr/sbin:/sbin
* * * * * cd /path/to/site && php bin/grav scheduler >> /tmp/site-scheduler.log 2>&1
```

`bin/grav scheduler -d` prints each job's errors, which is how to find this.

`JobQueue::health()` is the evidence an admin screen shows: `pending`, `failed`, how long the oldest runnable job has waited (from `created_at`, not from the backoff), and `stale` once that passes a threshold (900 seconds by default). Nothing in the kit can ask Grav whether cron is wired up, so a queue that stopped draining is how the site finds out.

## The inline drain

`InlineDrain` runs the ids a request queued after the response has left, within a budget (3 seconds by default, 30 at most), and leaves everything else to the worker. Only this request's jobs are run, so nobody's click pays for somebody else's backlog. Each job is claimed with the same conditional UPDATE the worker uses, so a cron tick in the same second cannot run one twice.

Its rules:

- It never throws and never prints. Handler output, warnings with `display_errors` on, a logger that echoes: all of it goes into a buffer that is discarded, and the caller's buffer stack is left as it was.
- It runs once per request. `PendingDrain::take()` hands the list over once; the first call drains, every later call on any `InlineDrain` over the same `PendingDrain` returns zeros, and work queued during or after the drain is left for the worker.
- It runs nothing while the database connection is inside a transaction. A request that died mid-transaction still has it open when the shutdown hooks run, and its jobs would otherwise run and then be rolled back with everything else.
- It holds only the runner, the pending list and a log callback, and reads no Grav service, session or config, so it is safe to call from `register_shutdown_function` at any point in PHP's shutdown.
- Handlers must not touch the session. Grav closes it before `onShutdown`, and a handler that reopens it holds the session lock for the whole drain.

### Two hooks

On Grav 2 develop, `onShutdown` does not fire after API requests: `Grav::shutdown()` throws inside `Session::close()` before it reaches the event. So a plugin wires the drain to both Grav's `onShutdown` and a plain `register_shutdown_function` fallback, and relies on the drain being a no-op the second time:

```php
public static function getSubscribedEvents(): array
{
    return [
        'onPluginsInitialized' => ['onPluginsInitialized', 0],
        'onShutdown' => ['drainQueuedJobs', 0],
        // ...
    ];
}

public function onPluginsInitialized(): void
{
    if (PHP_SAPI !== 'cli') {
        // Registered now, it would run BEFORE Grav's own shutdown (Grav
        // registers that at the end of process()), which is before the session
        // is closed and before the response has left. Registering it from
        // inside a shutdown function appends it behind Grav's instead.
        register_shutdown_function(function (): void {
            register_shutdown_function([$this, 'drainQueuedJobs']);
        });
    }
}

public function drainQueuedJobs(): void
{
    if (PHP_SAPI === 'cli') {
        return;
    }

    try {
        $services = HelpdeskPro::services();
        $pending = $services->pendingDrain();
        if ($pending->drained() || $pending->isEmpty()) {
            return;
        }

        if (!(bool)$config->get('plugins.helpdesk-pro.jobs.inline_drain', true)) {
            $pending->clear();      // cron will get them
            return;
        }

        (new InlineDrain(
            $pending,
            $services->runner(),
            (int)$config->get('plugins.helpdesk-pro.jobs.inline_budget_seconds', InlineDrain::DEFAULT_BUDGET),
            fn (string $line) => $grav['log']->debug('helpdesk-pro: ' . $line),
        ))->run();
    } catch (\Throwable $e) {
        // Building the services is the only part that can throw here.
    }
}
```

What PHP does with the two hooks, checked on PHP 8.4:

- Shutdown functions run in the order they were registered, and one registered from inside a shutdown function is appended to the end of that list. The trampoline above therefore puts the fallback after Grav's `shutdown()`, whichever order the two were registered in during the request.
- On a normal request Grav's `shutdown()` closes the session, finishes the response and fires `onShutdown`, which drains. The fallback then runs, finds the list taken, and does nothing.
- When Grav's `shutdown()` throws, PHP passes the exception to the exception handler Grav installed. If that handler returns, the remaining shutdown functions still run and the fallback drains. If it ends the process with `exit()` (Whoops does after rendering, when its handler asks to quit), nothing registered after Grav's shutdown runs, including the fallback, and the jobs wait for the worker exactly as they would without an inline drain. Only a function registered before Grav's (which runs before the response has left) or an object destructor (which PHP still calls after such an exit) runs in that case.
- A fallback registered directly during the request, without the trampoline, always runs before Grav's `shutdown()`, so it wins every time: the visitor waits for the drain and the session is still open while it runs. Use the trampoline.

`tests/Integration/Jobs/InlineDrainShutdownTest.php` runs a real PHP process through both cases (both hooks drain; Grav's shutdown throws and the fallback drains alone) and checks that the job ran once and that stdout holds only the response.

### The two hosting cases

On PHP-FPM, `fastcgi_finish_request()` has already sent the response by the time `onShutdown` fires, so the drain costs the visitor nothing. On mod_php there is no such call: Grav asks the browser to close the connection, but the PHP worker is still occupied for the length of the drain. That is why the budget defaults to three seconds. The drain's one log line says which case ran.

### Deadlines

`JobDeadline` is shared by the daemon, the cron worker and the inline drain through the runner. With pcntl (most CLI builds) an alarm interrupts a blocked handler; without it (nearly every FastCGI build) the overrun is noticed when the handler returns. Either way the job fails with `JobTimedOut`, keeps the attempt, and retries with backoff. `new JobDeadline(0)` turns it off. After a job trips its deadline the daemon exits with code 1, because a handler that overran may still hold a socket or a lock PHP cannot take back.

## Switching from KahunaCart / Forum Pro

Namespaces move from `Grav\Plugin\KahunaCart\Jobs\` and `Grav\Plugin\ForumPro\Jobs\` to `TrilbyMedia\GravDbKit\Jobs\` (which Strauss then prefixes per plugin). Class and method names are KahunaCart's unless listed here.

### From KahunaCart

- **`JobQueue::__construct`** was `(Connection $db, int $staleLockSeconds = 300, ?callable $captureSiteUrl = null, ?callable $clock = null, ?JobTrigger $trigger = null)`. It is now `(Connection $db, ?KitTables $tables = null, int $staleLockSeconds = 300, ?callable $payloadStamp = null, Clock|callable|null $clock = null, ?JobTrigger $trigger = null)`. `$tables` is new in second position, so positional callers must be updated; named arguments (`trigger: ...`) keep working. Pass `new KitTables(jobs: 'kahunacart_jobs', ...)` to keep the existing table.
- **`$captureSiteUrl` became `$payloadStamp`.** It was `callable(): string` and the queue added the URL under `SiteUrl::PAYLOAD_KEY` itself; it is now `callable(array $payload): array` and the stamp does the adding (see "The payload stamp" above for KahunaCart's rule written that way). With no stamp, nothing is added; the old default of `SiteUrl::capture(...)` is gone because it booted Grav.
- **Clocks** on `JobQueue`, `JobRunner` and `JobDaemon` accept a `Support\Clock` as well as a callable.
- **`JobDaemon`'s default clock** is now the queue's clock (it was `time()`), the same rule `JobRunner` already followed.
- **`JobDaemon::__construct`** takes `?DaemonHeartbeat $heartbeat` where it took KahunaCart's `Ops\WorkerHeartbeat` class. `DaemonHeartbeat` is an interface with the same `beat(int $startedAt, int $jobsDone, ?int $now = null, ?int $memoryBytes = null, bool $force = false)` and `clear()`; KahunaCart's `WorkerHeartbeat` only needs `implements DaemonHeartbeat`.
- **`WorkerTick`** was a static `WorkerTick::fire(JobQueue $queue, ?int $now, ?callable $dispatch, ?callable $logger): array` that fired the Grav event `onKahunaCartWorkerTick` (the `EVENT` constant is gone). It is now an instance: `new WorkerTick(iterable $listeners = [], ?callable $logger = null)`, `listen(callable $onTick)`, `count()`, and `fire(JobQueue $queue, ?int $now = null): array{now, listeners, failed}`. Listeners are `callable(JobQueue $queue, int $now): void` instead of a listener reading `$event['queue']` and `$event['now']`. Each listener is isolated, so one that throws no longer stops the ones after it. KahunaCart keeps its event by registering one listener that fires `onKahunaCartWorkerTick`.
- **`PendingDrain`** gained `take()` and `drained()`. After `take()`, `wake()` collects nothing. `InlineDrain::run()` now uses `take()` instead of `ids()` + `clear()`, which is what makes a second call a no-op.
- **`InlineDrain::run()`** also runs nothing while the connection is inside a transaction (it reports every id as skipped), writes its log line from inside its discarding buffer, and closes exactly the buffers above the caller's level instead of one `ob_end_clean()`.
- **`JobQueue::release()`** answers from the row after the update rather than from the UPDATE's row count (MySQL counts only rows whose values changed), and its UPDATE is now conditional on no live worker holding the job.
- **Log lines** no longer start with `kahunacart: `. The registry, drain, daemon and tick pass bare messages to the plugin's logger callback, which adds the plugin's name.
- **`JobTimedOut`'s message** says "Raise the job timeout setting" instead of naming `jobs.job_timeout_seconds`.
- **`JobDaemon`'s stop messages** name the option keys (`max_time`, `max_jobs`, `max_memory`) rather than KahunaCart's `--max-time` style flags.
- **New:** `JobQueue::table()`, `staleLockSeconds()`, `inTransaction()`, `static backoff(int $attempts)`; `JobRunner::hostWorkerId()` (the `gethostname() . ':' . getmypid()` id KahunaCart built in three places), `queue()`, `registerAll(JobHandlerRegistry)`, `types()`, and an optional `$maxJobs` on `run(int $timeBudgetSeconds = 50, int $maxJobs = 0)`; `JobDaemon::phpMemoryLimit()` (was `WorkCommand::memoryLimitBytes()`).
- **Not ported:** `BuiltinHandlers` and `Jobs/Handlers/*` (KahunaCart's own work) and `Ops\WorkerHeartbeat` (a settings-table implementation of `DaemonHeartbeat` that stays in KahunaCart).

### From Forum Pro

Forum Pro had a smaller queue (`JobQueue` with `enqueue`, `claim`, `complete`, `fail`, `stats`, and `JobRunner::run(int $maxJobs = 50, int $maxSeconds = 50)`).

- `JobQueue::__construct(Connection $db, int $staleLockSeconds = 300)` becomes `new JobQueue($db, new KitTables(jobs: 'forum_jobs', ...))`. Run `InfraTables::jobs()` as a new migration step first: it adds the `cancel_requested_at`, `cancelled_at` and `dedupe_key` columns and the dedupe index the kit's queue reads.
- `JobHandler` loses `type(): string`. Register with `$runner->register($handler->type(), $handler)`, or through a `JobHandlerRegistry`; the existing handlers keep their `type()` method as a plain method and implement the kit's `JobHandler`.
- `JobRunner::register(JobHandler $handler)` becomes `register(string $type, JobHandler $handler)`.
- `JobRunner::run($maxJobs, $maxSeconds)` becomes `run($maxSeconds, $maxJobs)`: the order is swapped and `$maxJobs` defaults to no cap. Forum Pro's behaviour is `run(50, 50)`. The result gains `deferred`.
- `last_error` now reads `ExceptionClass: message` instead of the bare message, and a missing handler's message is `No handler registered for job type: <type>`.
- `enqueue()` gains `$dedupeKey`, and `claim()` now skips cancelled jobs; everything else Forum Pro called behaves the same.
