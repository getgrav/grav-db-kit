<?php

declare(strict_types=1);

namespace TrilbyMedia\GravDbKit\Testing;

use TrilbyMedia\GravDbKit\Support\Clock;

/**
 * A clock that only moves when a test moves it, so expiry, windows and
 * backoff can be tested without sleeping.
 */
final class FrozenClock implements Clock
{
    public function __construct(private int $now = 1_700_000_000)
    {
    }

    public function now(): int
    {
        return $this->now;
    }

    public function set(int $now): void
    {
        $this->now = $now;
    }

    public function advance(int $seconds): void
    {
        $this->now += $seconds;
    }
}
