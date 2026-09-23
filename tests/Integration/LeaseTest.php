<?php

declare(strict_types=1);

namespace TrilbyMedia\GravDbKit\Tests\Integration;

use PHPUnit\Framework\TestCase;
use TrilbyMedia\GravDbKit\Database\Connection;
use TrilbyMedia\GravDbKit\Database\KitOptions;
use TrilbyMedia\GravDbKit\Database\KitTables;
use TrilbyMedia\GravDbKit\Database\Lease;
use TrilbyMedia\GravDbKit\Database\LeaseUnavailable;
use TrilbyMedia\GravDbKit\Database\Migrator;
use TrilbyMedia\GravDbKit\Testing\FrozenClock;
use TrilbyMedia\GravDbKit\Tests\Support\TestEngine;

final class LeaseTest extends TestCase
{
    private Connection $db;
    private FrozenClock $clock;

    protected function setUp(): void
    {
        $this->db = TestEngine::fresh();
        // The migrator's bootstrap is what creates the locks table.
        (new Migrator($this->db, []))->bootstrap();
        $this->clock = new FrozenClock(1_000_000);
    }

    public function testAcquireReleaseAndAcquireAgain(): void
    {
        $lease = $this->lease();

        self::assertTrue($lease->acquire('drain', 60));
        self::assertTrue($lease->holds('drain'));
        self::assertSame(1_000_060, (int)$this->db->fetchValue('SELECT expires_at FROM kit_locks WHERE name = ?', ['drain']));

        $lease->release('drain');
        self::assertFalse($lease->holds('drain'));
        self::assertSame(0, (int)$this->db->fetchValue('SELECT COUNT(*) FROM kit_locks'));

        self::assertTrue($lease->acquire('drain', 60));
    }

    public function testALiveLeaseRefusesAnotherHolder(): void
    {
        $mine = $this->lease();
        $theirs = $this->lease(TestEngine::sibling());

        self::assertTrue($mine->acquire('drain', 60));
        self::assertFalse($theirs->acquire('drain', 60));
        self::assertFalse($theirs->holds('drain'));

        // A failed attempt leaves the holder's row exactly as it was, so the
        // holder's own release still finds it.
        $mine->release('drain');
        self::assertSame(0, (int)$this->db->fetchValue('SELECT COUNT(*) FROM kit_locks'));
        self::assertTrue($theirs->acquire('drain', 60));
    }

    public function testAnExpiredLeaseIsTakenOverAndTheOldHolderCannotReleaseIt(): void
    {
        $crashed = $this->lease();
        $next = $this->lease(TestEngine::sibling());

        self::assertTrue($crashed->acquire('drain', 60));
        $this->clock->advance(59);
        self::assertFalse($next->acquire('drain', 60), 'still live at the last second');

        $this->clock->advance(2);
        self::assertTrue($next->acquire('drain', 60), 'expired, so fair game');

        // The first holder wakes up and lets go of what it thinks it holds.
        $crashed->release('drain');
        self::assertSame(1, (int)$this->db->fetchValue('SELECT COUNT(*) FROM kit_locks'), 'the new holder keeps its lease');
        self::assertFalse($crashed->renew('drain'), 'nor can it renew one it lost');
    }

    public function testRenewPushesTheExpiryWhileTheLeaseIsOurs(): void
    {
        $lease = $this->lease();
        $lease->acquire('drain', 60);

        $this->clock->advance(50);
        self::assertTrue($lease->renew('drain', 60));
        self::assertSame(1_000_110, (int)$this->db->fetchValue('SELECT expires_at FROM kit_locks WHERE name = ?', ['drain']));

        $this->clock->advance(55);
        self::assertFalse($this->lease(TestEngine::sibling())->acquire('drain', 60), 'renewed, so still live');
        self::assertFalse($this->lease()->renew('never-taken'));
    }

    /**
     * Two leases taken by one process must not share an owner, or the second
     * release deletes the first's row (KahunaCart's split-refund case).
     */
    public function testTwoLeasesInOneProcessHaveOwnersOfTheirOwn(): void
    {
        $options = new KitOptions(lockOwner: 'same-host:1');
        $first = new Lease($this->db, null, $options, $this->clock);
        $second = new Lease($this->db, null, $options, $this->clock);

        self::assertTrue($first->acquire('refund:order:7', 60));
        self::assertFalse($second->acquire('refund:order:7', 60));

        $second->release('refund:order:7');
        self::assertSame(1, (int)$this->db->fetchValue('SELECT COUNT(*) FROM kit_locks'));

        $owner = (string)$this->db->fetchValue('SELECT locked_by FROM kit_locks');
        self::assertStringStartsWith('same-host:1:', $owner);
        self::assertLessThanOrEqual(64, \strlen($owner));
    }

    public function testAcquiringAgainWhileHeldRenewsIt(): void
    {
        $lease = $this->lease();
        self::assertTrue($lease->acquire('drain', 60));
        $this->clock->advance(30);

        self::assertTrue($lease->acquire('drain', 60));
        self::assertSame(1_000_090, (int)$this->db->fetchValue('SELECT expires_at FROM kit_locks WHERE name = ?', ['drain']));
    }

    public function testRunReleasesAfterwardsEvenWhenTheWorkThrows(): void
    {
        $lease = $this->lease();

        self::assertSame('done', $lease->run('job', static fn (): string => 'done', 60));
        self::assertSame(0, (int)$this->db->fetchValue('SELECT COUNT(*) FROM kit_locks'));

        try {
            $lease->run('job', static function (): never {
                throw new \DomainException('work failed');
            });
        } catch (\DomainException) {
        }
        self::assertSame(0, (int)$this->db->fetchValue('SELECT COUNT(*) FROM kit_locks'));
    }

    public function testRunRefusesWithItsOwnExceptionWhenHeldElsewhere(): void
    {
        $this->lease(TestEngine::sibling())->acquire('job', 60);

        $this->expectException(LeaseUnavailable::class);
        $this->lease()->run('job', static fn (): bool => true);
    }

    public function testTheDefaultTtlIsTheConfiguredOne(): void
    {
        $lease = new Lease($this->db, null, new KitOptions(lockTtl: 42), $this->clock);
        $lease->acquire('drain');

        self::assertSame(1_000_042, (int)$this->db->fetchValue('SELECT expires_at FROM kit_locks WHERE name = ?', ['drain']));
    }

    /** A lease inside an open transaction must not poison it (PostgreSQL aborts a transaction on any failed statement). */
    public function testALeaseCanBeTakenInsideATransactionEvenWhenItIsRefused(): void
    {
        $this->lease(TestEngine::sibling())->acquire('busy', 60);
        $lease = $this->lease();

        $this->db->transaction(function (Connection $c) use ($lease): void {
            self::assertFalse($lease->acquire('busy', 60));
            self::assertTrue($lease->acquire('free', 60));
            $c->fetchValue('SELECT COUNT(*) FROM kit_locks');
        });

        self::assertTrue($lease->holds('free'));
    }

    public function testTheLocksTableComesFromKitTables(): void
    {
        $tables = KitTables::withPrefix('kit_y');
        (new Migrator($this->db, [], $tables))->bootstrap();

        $lease = new Lease($this->db, $tables, null, $this->clock);
        $lease->acquire('drain', 60);

        self::assertSame(1, (int)$this->db->fetchValue('SELECT COUNT(*) FROM kit_y_locks'));
        self::assertSame(0, (int)$this->db->fetchValue('SELECT COUNT(*) FROM kit_locks'));
    }

    private function lease(?Connection $db = null): Lease
    {
        return new Lease($db ?? $this->db, null, null, $this->clock);
    }
}
