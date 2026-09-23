<?php

declare(strict_types=1);

namespace TrilbyMedia\GravDbKit\Database;

/**
 * Lease::run() could not take its lease because another process holds it.
 *
 * Its own class so a caller can answer "busy, try again" (a 409, a skipped
 * drain) to this without answering it to everything else that goes wrong
 * inside the lease.
 */
final class LeaseUnavailable extends \RuntimeException
{
}
