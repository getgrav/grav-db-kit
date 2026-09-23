<?php

declare(strict_types=1);

namespace TrilbyMedia\GravDbKit\Testing;

use TrilbyMedia\GravDbKit\Events\EventSink;

/**
 * Records every emitted event for a test to inspect. Set `$explode` to make it
 * throw instead, which is how a test proves a failing consumer cannot break
 * the write that emitted the event.
 */
final class SpySink implements EventSink
{
    /** @var list<array{event: string, payload: array<string, mixed>}> */
    public array $events = [];

    public bool $explode = false;

    public function emit(string $event, array $payload): void
    {
        if ($this->explode) {
            throw new \RuntimeException('sink failure');
        }
        $this->events[] = ['event' => $event, 'payload' => $payload];
    }

    /** @return list<array<string, mixed>> payloads for one event name */
    public function of(string $event): array
    {
        $out = [];
        foreach ($this->events as $row) {
            if ($row['event'] === $event) {
                $out[] = $row['payload'];
            }
        }

        return $out;
    }

    /** @return list<string> */
    public function names(): array
    {
        return array_column($this->events, 'event');
    }

    public function reset(): void
    {
        $this->events = [];
    }
}
