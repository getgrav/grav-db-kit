<?php

declare(strict_types=1);

namespace TrilbyMedia\GravDbKit\Support;

/** The real clock: time(). What every class uses when it is not handed one. */
final class SystemClock implements Clock
{
    public function now(): int
    {
        return time();
    }
}
