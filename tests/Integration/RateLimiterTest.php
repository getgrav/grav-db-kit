<?php

declare(strict_types=1);

namespace TrilbyMedia\GravDbKit\Tests\Integration;

use TrilbyMedia\GravDbKit\Database\Connection;
use TrilbyMedia\GravDbKit\Database\KitTables;
use TrilbyMedia\GravDbKit\Schema\InfraTables;
use TrilbyMedia\GravDbKit\Testing\FrozenClock;
use TrilbyMedia\GravDbKit\Support\RateLimiter;
use TrilbyMedia\GravDbKit\Tests\Support\TestEngine;
use PHPUnit\Framework\TestCase;

/**
 * Windows are aligned to the epoch, so every test here picks a $now whose
 * window boundaries are obvious: with a 60 second window, 1000 sits inside the
 * window that runs [960, 1020).
 */
final class RateLimiterTest extends TestCase
{
    /** Every call names a bucket; these tests use one and vary the key. */
    private const BUCKET = 'test';

    private const WINDOW = 60;
    private const NOW = 1000;
    private const WINDOW_START = 960;
    private const WINDOW_END = 1020;

    public function testTheWindowAllowsExactlyTheLimit(): void
    {
        $limiter = new RateLimiter(TestEngine::migrated());

        foreach ([2, 1, 0] as $expectedRemaining) {
            $result = $limiter->hit(self::BUCKET, 'ip:1.2.3.4', 3, self::WINDOW, self::NOW);

            $this->assertTrue($result->allowed);
            $this->assertSame($expectedRemaining, $result->remaining);
            $this->assertSame(0, $result->retryAfter, 'an allowed call never asks the client to wait');
        }
    }

    public function testTheCallAfterTheLimitIsDeniedWithATimeToWait(): void
    {
        $limiter = new RateLimiter(TestEngine::migrated());
        for ($i = 0; $i < 3; $i++) {
            $limiter->hit(self::BUCKET, 'ip:1.2.3.4', 3, self::WINDOW, self::NOW);
        }

        $result = $limiter->hit(self::BUCKET, 'ip:1.2.3.4', 3, self::WINDOW, self::NOW);

        $this->assertFalse($result->allowed);
        $this->assertSame(0, $result->remaining);
        $this->assertSame(self::WINDOW_END - self::NOW, $result->retryAfter);
    }

    public function testRetryAfterCountsDownToTheEndOfTheWindow(): void
    {
        $limiter = new RateLimiter(TestEngine::migrated());
        $limiter->hit(self::BUCKET, 'k', 1, self::WINDOW, self::NOW);

        $this->assertSame(20, $limiter->hit(self::BUCKET, 'k', 1, self::WINDOW, self::NOW)->retryAfter);
        $this->assertSame(2, $limiter->hit(self::BUCKET, 'k', 1, self::WINDOW, self::WINDOW_END - 2)->retryAfter);
        $this->assertSame(1, $limiter->hit(self::BUCKET, 'k', 1, self::WINDOW, self::WINDOW_END - 1)->retryAfter);
    }

    public function testANewWindowStartsTheCountOver(): void
    {
        $limiter = new RateLimiter(TestEngine::migrated());
        $limiter->hit(self::BUCKET, 'k', 2, self::WINDOW, self::NOW);
        $limiter->hit(self::BUCKET, 'k', 2, self::WINDOW, self::NOW);

        $this->assertFalse($limiter->hit(self::BUCKET, 'k', 2, self::WINDOW, self::NOW)->allowed, 'spent in this window');

        $fresh = $limiter->hit(self::BUCKET, 'k', 2, self::WINDOW, self::WINDOW_END);

        $this->assertTrue($fresh->allowed, 'the next window is a clean slate');
        $this->assertSame(1, $fresh->remaining);
    }

    public function testTheLastSecondOfAWindowIsStillTheSameWindow(): void
    {
        $limiter = new RateLimiter(TestEngine::migrated());
        $limiter->hit(self::BUCKET, 'k', 1, self::WINDOW, self::WINDOW_START);

        $this->assertFalse(
            $limiter->hit(self::BUCKET, 'k', 1, self::WINDOW, self::WINDOW_END - 1)->allowed,
            'the window is closed at its start and open at its end'
        );
    }

    public function testRollingForwardKeepsOneRowPerKey(): void
    {
        $db = TestEngine::migrated();
        $limiter = new RateLimiter($db, pruneOdds: 0);

        $limiter->hit(self::BUCKET, 'k', 5, self::WINDOW, self::NOW);
        $limiter->hit(self::BUCKET, 'k', 5, self::WINDOW, self::WINDOW_END);
        $limiter->hit(self::BUCKET, 'k', 5, self::WINDOW, self::WINDOW_END + self::WINDOW);

        $this->assertSame(1, $this->rowCount($db), 'a key is a row, not a row per window');
    }

    public function testKeysAreCountedApartFromEachOther(): void
    {
        $limiter = new RateLimiter(TestEngine::migrated());
        $limiter->hit(self::BUCKET, 'ip:1.1.1.1', 1, self::WINDOW, self::NOW);

        $this->assertFalse($limiter->hit(self::BUCKET, 'ip:1.1.1.1', 1, self::WINDOW, self::NOW)->allowed);
        $this->assertTrue($limiter->hit(self::BUCKET, 'ip:2.2.2.2', 1, self::WINDOW, self::NOW)->allowed);
    }

    public function testAZeroLimitTurnsTheBucketOffRatherThanDenyingEverything(): void
    {
        $db = TestEngine::migrated();
        $limiter = new RateLimiter($db, pruneOdds: 0);

        $result = $limiter->hit(self::BUCKET, 'k', 0, self::WINDOW, self::NOW);

        $this->assertTrue($result->allowed, 'clearing a limit is how a merchant turns a bucket off');
        $this->assertSame(0, $result->retryAfter);
        $this->assertSame(0, $this->rowCount($db), 'and a bucket that is off costs no writes');
    }

    public function testTwoBucketsCountTheSameKeyApart(): void
    {
        $limiter = new RateLimiter(TestEngine::migrated());
        $limiter->hit('checkout', '1.1.1.1', 1, self::WINDOW, self::NOW);

        $this->assertFalse($limiter->hit('checkout', '1.1.1.1', 1, self::WINDOW, self::NOW)->allowed);
        $this->assertTrue(
            $limiter->hit('cart_write', '1.1.1.1', 1, self::WINDOW, self::NOW)->allowed,
            'one address browsing hard must not spend its checkout budget'
        );
    }

    public function testDeniedCallsDoNotPostponeTheWindow(): void
    {
        $limiter = new RateLimiter(TestEngine::migrated());
        for ($i = 0; $i < 50; $i++) {
            $limiter->hit(self::BUCKET, 'flooder', 1, self::WINDOW, self::NOW);
        }

        $this->assertTrue(
            $limiter->hit(self::BUCKET, 'flooder', 1, self::WINDOW, self::WINDOW_END)->allowed,
            'hammering through a window does not extend it'
        );
    }

    public function testAKeyMayBeAHash(): void
    {
        $limiter = new RateLimiter(TestEngine::migrated());
        $key = 'lic:' . hash('sha256', 'a secret nobody stores here');

        $this->assertTrue($limiter->hit(self::BUCKET, $key, 2, self::WINDOW, self::NOW)->allowed);
        $this->assertTrue($limiter->hit(self::BUCKET, $key, 2, self::WINDOW, self::NOW)->allowed);
        $this->assertFalse($limiter->hit(self::BUCKET, $key, 2, self::WINDOW, self::NOW)->allowed);
    }

    public function testPruneDropsWindowsThatStartedBeforeTheCutoff(): void
    {
        $db = TestEngine::migrated();
        $limiter = new RateLimiter($db, pruneOdds: 0);

        $limiter->hit(self::BUCKET, 'stale', 10, self::WINDOW, self::NOW);
        $limiter->hit(self::BUCKET, 'fresh', 10, self::WINDOW, self::NOW + 10 * self::WINDOW);

        $this->assertSame(2, $this->rowCount($db));
        $this->assertSame(1, $limiter->prune(self::NOW + 5 * self::WINDOW), 'one stale row went');
        $this->assertSame([self::BUCKET . ':fresh'], $this->keys($db));
    }

    public function testPruneKeepsWindowsOnTheCutoffItself(): void
    {
        $db = TestEngine::migrated();
        $limiter = new RateLimiter($db, pruneOdds: 0);
        $limiter->hit(self::BUCKET, 'k', 10, self::WINDOW, self::NOW);

        $this->assertSame(0, $limiter->prune(self::WINDOW_START), 'the cutoff is exclusive');
        $this->assertSame(1, $this->rowCount($db));
    }

    public function testStaleRowsAreSweptWithoutAnybodyAskingThemTo(): void
    {
        $db = TestEngine::migrated();
        $limiter = new RateLimiter($db);

        $limiter->hit(self::BUCKET, 'abandoned', 10, self::WINDOW, self::NOW);

        // The sweep is opportunistic — roughly one call in fifty — so a busy
        // key is what triggers it. A thousand calls make a miss vanishingly
        // unlikely (0.98^1000 is about one in six hundred million).
        $later = self::NOW + 100 * self::WINDOW;
        for ($i = 0; $i < 1000; $i++) {
            $limiter->hit(self::BUCKET, 'busy', 100000, self::WINDOW, $later);
        }

        $this->assertSame([self::BUCKET . ':busy'], $this->keys($db), 'the abandoned key aged out on its own');
    }

    public function testAShortBucketsSweepLeavesALongerBucketsCurrentWindowAlone(): void
    {
        $db = TestEngine::migrated();
        $limiter = new RateLimiter($db);
        $hour = 3600;
        $now = 7 * $hour + 1800; // half an hour into an hourly window

        $limiter->hit('hourly', 'k', 1, $hour, $now);

        // Enough one-minute calls to all but guarantee several sweeps, each
        // with a cutoff two minutes back: well after the hourly row's start.
        for ($i = 0; $i < 1000; $i++) {
            $limiter->hit('minute', 'busy', 100000, self::WINDOW, $now);
        }

        $this->assertFalse(
            $limiter->hit('hourly', 'k', 1, $hour, $now)->allowed,
            'the hourly count survived the one-minute bucket\'s sweeps'
        );
    }

    public function testPruneCanBeLimitedToOneBucket(): void
    {
        $db = TestEngine::migrated();
        $limiter = new RateLimiter($db, pruneOdds: 0);
        $limiter->hit('a', 'k', 10, self::WINDOW, self::NOW);
        $limiter->hit('b', 'k', 10, self::WINDOW, self::NOW);

        $this->assertSame(1, $limiter->prune(self::NOW + 5 * self::WINDOW, 'a'));
        $this->assertSame(['b:k'], $this->keys($db));
    }

    // ------------------------------------------------------ kit additions

    public function testAKeyLongerThanTheColumnIsHashedRatherThanCut(): void
    {
        $db = TestEngine::migrated();
        $limiter = new RateLimiter($db, pruneOdds: 0);
        $prefix = str_repeat('x', 190);

        $limiter->hit(self::BUCKET, $prefix . 'a', 1, self::WINDOW, self::NOW);

        $this->assertTrue(
            $limiter->hit(self::BUCKET, $prefix . 'b', 1, self::WINDOW, self::NOW)->allowed,
            'two long keys sharing a prefix are two keys'
        );
        $this->assertFalse($limiter->hit(self::BUCKET, $prefix . 'a', 1, self::WINDOW, self::NOW)->allowed);
        $this->assertSame(2, $this->rowCount($db));
    }

    public function testTheClockDecidesTheWindowWhenNoNowIsGiven(): void
    {
        $clock = new FrozenClock(self::NOW);
        $limiter = new RateLimiter(TestEngine::migrated(), null, 0, $clock);

        $limiter->hit(self::BUCKET, 'k', 1, self::WINDOW);
        $this->assertSame(self::WINDOW_END - self::NOW, $limiter->hit(self::BUCKET, 'k', 1, self::WINDOW)->retryAfter);

        $clock->set(self::WINDOW_END);
        $this->assertTrue($limiter->hit(self::BUCKET, 'k', 1, self::WINDOW)->allowed);
    }

    public function testTheTableComesFromKitTables(): void
    {
        $db = TestEngine::migrated();
        $tables = KitTables::withPrefix('kit_z');
        InfraTables::rateLimits($db->dialect(), $tables)($db);

        (new RateLimiter($db, $tables, 0))->hit(self::BUCKET, 'k', 5, self::WINDOW, self::NOW);

        $this->assertSame(1, (int)$db->fetchValue('SELECT COUNT(*) FROM kit_z_rate_limits'));
        $this->assertSame(0, $this->rowCount($db));
    }

    public function testABucketMustFitItsColumn(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        (new RateLimiter(TestEngine::migrated()))->hit(str_repeat('b', 65), 'k', 1, self::WINDOW, self::NOW);
    }

    public function testAnonymizeIsStableAndCaseBlind(): void
    {
        $this->assertSame(RateLimiter::anonymize(' Person@Example.test '), RateLimiter::anonymize('person@example.test'));
        $this->assertSame(64, \strlen(RateLimiter::anonymize('1.2.3.4')));
    }

    /** @return list<string> "bucket:key" for every row, sorted */
    private function keys(Connection $db): array
    {
        $rows = $db->fetchAll('SELECT bucket, rl_key FROM kit_rate_limits ORDER BY bucket, rl_key');

        return array_map(static fn (array $row): string => $row['bucket'] . ':' . $row['rl_key'], $rows);
    }

    private function rowCount(Connection $db): int
    {
        return (int)$db->fetchValue('SELECT COUNT(*) FROM kit_rate_limits');
    }
}
