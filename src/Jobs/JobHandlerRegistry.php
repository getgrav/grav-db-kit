<?php

declare(strict_types=1);

namespace TrilbyMedia\GravDbKit\Jobs;

/**
 * Every handler a worker knows about, collected once per worker run.
 *
 * The queue is the one place where work can be slow, retried, and safely
 * absent from the request that caused it — which makes it exactly where an
 * add-on wants to put anything that talks to somebody else's server. A plugin
 * typically fills this from an event of its own (KahunaCart's
 * `onKahunaCartRegisterJobHandlers`), so its add-ons can run background work
 * on its worker rather than shipping a second worker of their own.
 *
 * The owning plugin registers its own handlers first, so first-wins means no
 * add-on can quietly take over one of its types.
 */
final class JobHandlerRegistry
{
    /**
     * Job types are dotted names — `order.confirmation_email`,
     * `stock.release_expired`. Dots and underscores are allowed because
     * existing types already use both; anything else is a packaging mistake.
     */
    public const TYPE_PATTERN = '/^[a-z0-9][a-z0-9._-]*$/';

    /** @var array<string, JobHandler> */
    private array $handlers = [];

    /** @var callable(string): void */
    private $logger;

    /**
     * The logger is a callback so this class stays free of any framework; the
     * plugin wires it to Grav's error log and adds its own name to the line.
     *
     * @param (callable(string): void)|null $logger
     */
    public function __construct(?callable $logger = null)
    {
        $this->logger = $logger ?? static function (): void {
        };
    }

    /**
     * First registration of a type wins; anything later claiming it is dropped
     * and logged.
     *
     * Registration usually runs inside an event every job-owning add-on
     * listens to, so a throw here takes the whole queue down — and every job in
     * it, including the emails somebody is waiting on. The two things that go
     * wrong (two add-ons picking one type, an add-on shipping a malformed one)
     * are both somebody else's packaging mistake, not a reason to stop the
     * queue. Losing one handler and logging why degrades; fataling does not.
     */
    public function register(string $type, JobHandler $handler): void
    {
        if (!preg_match(self::TYPE_PATTERN, $type)) {
            $this->log("invalid job type \"{$type}\" — registration ignored (types must match [a-z0-9][a-z0-9._-]*)");

            return;
        }

        if (isset($this->handlers[$type])) {
            $this->log("duplicate job type \"{$type}\" — the duplicate registration was ignored, the handler registered first still owns it");

            return;
        }

        $this->handlers[$type] = $handler;
    }

    public function get(string $type): ?JobHandler
    {
        return $this->handlers[$type] ?? null;
    }

    /** @return array<string, JobHandler> */
    public function all(): array
    {
        return $this->handlers;
    }

    /** @return list<string> */
    public function types(): array
    {
        return array_keys($this->handlers);
    }

    private function log(string $message): void
    {
        try {
            ($this->logger)($message);
        } catch (\Throwable) {
            // Registration never throws, and a logger that cannot write is not
            // a reason to start.
        }
    }
}
