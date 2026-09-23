<?php

declare(strict_types=1);

namespace TrilbyMedia\GravDbKit\Support;

/**
 * Where the kit asks what time it is.
 *
 * Every time the kit stores is UTC epoch seconds in a BIGINT, so the whole
 * contract is one integer. Code that decides anything by time (lease expiry,
 * rate-limit windows, job backoff) takes a Clock rather than calling time()
 * itself, so a test can freeze or advance it instead of sleeping.
 */
interface Clock
{
    /** Seconds since the Unix epoch. */
    public function now(): int;
}
