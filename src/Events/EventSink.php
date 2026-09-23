<?php

declare(strict_types=1);

namespace TrilbyMedia\GravDbKit\Events;

/**
 * Receives domain events from the write services after their transactions
 * commit. One emitter feeds every consumer (search, notifications, realtime,
 * add-ons). Implementations must never throw — a broken consumer must not
 * break the write that emitted the event.
 *
 * Event names are dotted (`post.created`, `ticket.status_changed`); payloads
 * are flat arrays of scalars, always carrying enough ids to route the event
 * without further queries.
 */
interface EventSink
{
    /**
     * @param array<string, mixed> $payload
     */
    public function emit(string $event, array $payload): void;
}
