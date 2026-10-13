<?php

declare(strict_types=1);

namespace TrilbyMedia\GravDbKit\Support;

/**
 * Which process and machine this is, for the strings that say who holds a
 * lease or a job.
 *
 * Hardened PHP-FPM pools often list getmypid() and gethostname() in
 * `disable_functions`, and PHP 8 then has no such function at all, so a plain
 * call is a fatal error. Both answers here fall back instead: 0 for the pid
 * and `unknown` for the host. Nothing matches on either value (a lease adds a
 * random tail of its own), so the fallback only makes the label less telling.
 */
final class Process
{
    /** The process id, or 0 when getmypid() is disabled or fails. */
    public static function id(): int
    {
        return function_exists('getmypid') ? (getmypid() ?: 0) : 0;
    }

    /** The machine's host name, or `unknown` when gethostname() is disabled or fails. */
    public static function host(): string
    {
        $host = function_exists('gethostname') ? gethostname() : false;

        return $host === false || $host === '' ? 'unknown' : $host;
    }

    /** `host:pid`, what the kit writes when it labels a lease or a job. */
    public static function label(): string
    {
        return self::host() . ':' . self::id();
    }
}
