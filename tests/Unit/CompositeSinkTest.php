<?php

declare(strict_types=1);

namespace TrilbyMedia\GravDbKit\Tests\Unit;

use PHPUnit\Framework\TestCase;
use TrilbyMedia\GravDbKit\Events\CompositeSink;
use TrilbyMedia\GravDbKit\Events\EventSink;
use TrilbyMedia\GravDbKit\Events\NullSink;
use TrilbyMedia\GravDbKit\Testing\SpySink;

final class CompositeSinkTest extends TestCase
{
    public function testEverySinkReceivesTheEventInOrder(): void
    {
        $first = new SpySink();
        $second = new SpySink();
        $composite = new CompositeSink($first, $second);

        $composite->emit('ticket.created', ['ticket_id' => 7]);

        self::assertSame([['ticket_id' => 7]], $first->of('ticket.created'));
        self::assertSame(['ticket.created'], $second->names());
    }

    /** A broken consumer must not break the write that emitted the event, nor the consumers after it. */
    public function testAThrowingSinkNeitherEscapesNorStopsTheOthers(): void
    {
        $broken = new SpySink();
        $broken->explode = true;
        $after = new SpySink();

        (new CompositeSink($broken, $after))->emit('post.created', ['post_id' => 1]);

        self::assertSame(['post.created'], $after->names());
    }

    public function testFailuresGoToTheReporterWithTheEventAndTheSink(): void
    {
        $broken = new SpySink();
        $broken->explode = true;
        $reported = [];

        $composite = (new CompositeSink($broken))->withErrorReporter(
            static function (\Throwable $e, string $event, EventSink $sink) use (&$reported): void {
                $reported[] = [$e->getMessage(), $event, $sink];
            }
        );
        $composite->emit('post.created', []);

        self::assertCount(1, $reported);
        self::assertSame(['sink failure', 'post.created'], [$reported[0][0], $reported[0][1]]);
        self::assertSame($broken, $reported[0][2]);
    }

    public function testAReporterThatThrowsIsIgnoredToo(): void
    {
        $broken = new SpySink();
        $broken->explode = true;
        $after = new SpySink();

        $composite = (new CompositeSink($broken, $after))->withErrorReporter(static function (): void {
            throw new \LogicException('the logger is down as well');
        });
        $composite->emit('post.created', []);

        self::assertSame(['post.created'], $after->names());
    }

    public function testSinksCanBeAddedAfterConstruction(): void
    {
        $composite = new CompositeSink(new NullSink());
        $late = new SpySink();
        $composite->add($late);

        $composite->emit('a', []);

        self::assertCount(2, $composite->sinks());
        self::assertSame(['a'], $late->names());
    }

    public function testTheSpyResets(): void
    {
        $spy = new SpySink();
        $spy->emit('a', []);
        $spy->reset();

        self::assertSame([], $spy->events);
    }
}
