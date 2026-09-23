<?php

declare(strict_types=1);

namespace TrilbyMedia\GravDbKit\Jobs;

/**
 * Where JobDaemon records that it is still alive.
 *
 * A daemon does not tick; it sits there. Nobody watching a site can tell a
 * daemon quietly waiting on an empty queue apart from one that died an hour
 * ago, and the difference is every email the site has not sent since. So the
 * loop leaves a beat — when it started, when it last went round, how many jobs
 * it has done and how much memory it is holding — and the plugin's status
 * screen reads it.
 *
 * An interface because where the beat lives is the plugin's business: a KV
 * row, a settings row, a file. The kit only promises to call it.
 *
 * Implementations must never throw. A site whose database went away has a much
 * louder problem than a missing heartbeat, and the loop reports that one itself.
 */
interface DaemonHeartbeat
{
    /**
     * Record where the daemon has got to.
     *
     * Called often — after every job and every empty poll — so an
     * implementation should throttle its own writes. `$force` marks the first
     * beat, where being exact matters more than being cheap.
     */
    public function beat(
        int $startedAt,
        int $jobsDone,
        ?int $now = null,
        ?int $memoryBytes = null,
        bool $force = false,
    ): void;

    /**
     * Forget the daemon.
     *
     * Called when the loop exits on its own terms — max jobs, max time, a
     * SIGTERM from the supervisor — because a beat left behind by a daemon
     * that stopped deliberately would have a status screen warning about a
     * worker nobody asked for. A daemon that dies never gets here, and the beat
     * it left is what the warning is made of.
     */
    public function clear(): void;
}
