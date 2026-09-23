<?php

declare(strict_types=1);

namespace TrilbyMedia\GravDbKit\Events;

/**
 * Fans one emission out to several sinks; each sink's failure is its own.
 *
 * A sink that throws is caught, the rest still run, and the failure goes to
 * the error reporter when one is set (a plugin passes its logger), so a broken
 * consumer is visible without being able to break the write that emitted the
 * event. A reporter that throws is ignored for the same reason.
 */
final class CompositeSink implements EventSink
{
    /** @var list<EventSink> */
    private array $sinks;

    /** @var (\Closure(\Throwable, string, EventSink): void)|null */
    private ?\Closure $onError = null;

    public function __construct(EventSink ...$sinks)
    {
        $this->sinks = array_values($sinks);
    }

    /**
     * The same sinks, reporting each sink failure to $onError.
     *
     * @param callable(\Throwable $error, string $event, EventSink $sink): void $onError
     */
    public function withErrorReporter(callable $onError): self
    {
        $copy = clone $this;
        $copy->onError = $onError(...);

        return $copy;
    }

    /** Append a sink; it receives every emission after the ones already added. */
    public function add(EventSink $sink): void
    {
        $this->sinks[] = $sink;
    }

    /** @return list<EventSink> */
    public function sinks(): array
    {
        return $this->sinks;
    }

    public function emit(string $event, array $payload): void
    {
        foreach ($this->sinks as $sink) {
            try {
                $sink->emit($event, $payload);
            } catch (\Throwable $e) {
                $this->report($e, $event, $sink);
            }
        }
    }

    private function report(\Throwable $e, string $event, EventSink $sink): void
    {
        if ($this->onError === null) {
            return;
        }

        try {
            ($this->onError)($e, $event, $sink);
        } catch (\Throwable) {
            // A reporter that fails has nothing left to report to.
        }
    }
}
