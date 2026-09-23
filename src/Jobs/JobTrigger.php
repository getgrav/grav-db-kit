<?php

declare(strict_types=1);

namespace TrilbyMedia\GravDbKit\Jobs;

/**
 * Told that a job has just been queued, so something can go and run it.
 *
 * The queue is durable on its own: a job sits in the table until a worker
 * claims it, and cron claiming once a minute is what every site has always
 * had. What cron cannot do is answer quickly — an email queued a second after
 * the tick waits fifty-nine seconds for no reason other than the clock. A
 * trigger is how something finds out sooner.
 *
 * One implementation ships with the kit: PendingDrain, which notes the id and
 * lets the request that queued it run the job after the response has gone to
 * the client. A site with a message broker could register a trigger that
 * publishes the id to a topic instead, and have a daemon — or its own
 * consumer — pick it up. Nothing here knows or cares which.
 *
 * A trigger must never throw and must never be slow. It runs inside
 * `enqueue()`, which runs inside whatever the request is doing: an order must
 * not fail because a broker was unreachable. The job is already in the table
 * by the time wake() is called, so the worst a broken trigger can cost is the
 * latency it was meant to save.
 */
interface JobTrigger
{
    /**
     * A job with this id has been queued and is ready to be claimed.
     *
     * @param int $jobId the row id `enqueue()` just returned
     */
    public function wake(int $jobId): void;
}
