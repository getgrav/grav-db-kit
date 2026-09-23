<?php

declare(strict_types=1);

namespace TrilbyMedia\GravDbKit\Tests\Unit;

use PHPUnit\Framework\TestCase;
use TrilbyMedia\GravDbKit\Support\SystemClock;
use TrilbyMedia\GravDbKit\Testing\FrozenClock;

final class FrozenClockTest extends TestCase
{
    public function testItOnlyMovesWhenMoved(): void
    {
        $clock = new FrozenClock(1000);

        self::assertSame(1000, $clock->now());
        $clock->advance(30);
        self::assertSame(1030, $clock->now());
        $clock->set(5);
        self::assertSame(5, $clock->now());
    }

    public function testTheSystemClockIsTime(): void
    {
        $before = time();
        $now = (new SystemClock())->now();

        self::assertGreaterThanOrEqual($before, $now);
        self::assertLessThanOrEqual(time(), $now);
    }
}
