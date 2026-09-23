<?php

declare(strict_types=1);

namespace TrilbyMedia\GravDbKit\Support;

/**
 * The answer to one `RateLimiter::hit()`: may this call proceed, how many more
 * the caller has in this window, and how long until the window turns over.
 *
 * `$retryAfter` is 0 on an allowed call and otherwise the whole number of
 * seconds an HTTP `Retry-After` header should carry — never 0 on a denial,
 * because a client told to retry after 0 seconds retries immediately.
 */
final readonly class RateLimitResult
{
    public function __construct(
        public bool $allowed,
        public int $remaining,
        public int $retryAfter,
    ) {
    }
}
