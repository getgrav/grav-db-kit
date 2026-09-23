<?php

declare(strict_types=1);

namespace TrilbyMedia\GravDbKit\Events;

/** Discards every event. For services built where nothing is listening (CLI tools, imports). */
final class NullSink implements EventSink
{
    public function emit(string $event, array $payload): void
    {
    }
}
