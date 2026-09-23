<?php

declare(strict_types=1);

namespace TrilbyMedia\GravDbKit\Tests\Unit;

use PHPUnit\Framework\TestCase;
use TrilbyMedia\GravDbKit\Database\KitOptions;
use TrilbyMedia\GravDbKit\Database\KitTables;

final class KitTablesTest extends TestCase
{
    public function testOnePrefixNamesEveryTable(): void
    {
        $t = KitTables::withPrefix('helpdesk');

        self::assertSame([
            'migrations' => 'helpdesk_migrations',
            'migrationsUnique' => 'uq_helpdesk_migrations',
            'locks' => 'helpdesk_locks',
            'kv' => 'helpdesk_kv',
            'jobs' => 'helpdesk_jobs',
            'rateLimits' => 'helpdesk_rate_limits',
        ], $t->toArray());
        self::assertSame('helpdesk_kv', KitTables::withPrefix('helpdesk_')->kv, 'a trailing underscore is not doubled');
    }

    /**
     * Forum Pro and KahunaCart keep the names they already have, and the
     * unique constraint follows the migrations table by default, which is
     * what both of them named it.
     */
    public function testExistingPluginNamesAreKeptAsGiven(): void
    {
        $forum = new KitTables(migrations: 'forum_migrations', locks: 'forum_locks');

        self::assertSame('forum_migrations', $forum->migrations);
        self::assertSame('uq_forum_migrations', $forum->migrationsUnique);
        self::assertSame('forum_locks', $forum->locks);
        self::assertSame('kit_kv', $forum->kv, 'names not given keep the kit default');

        $custom = new KitTables(migrations: 'm', migrationsUnique: 'uq_legacy');
        self::assertSame('uq_legacy', $custom->migrationsUnique);
    }

    public function testDefaultsAreTheKitPrefix(): void
    {
        self::assertEquals(KitTables::withPrefix('kit'), new KitTables());
    }

    public function testATableNameThatIsNotAnIdentifierIsRefused(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new KitTables(kv: 'kv; DROP TABLE users');
    }

    public function testKitOptionsDefaultsAndOwner(): void
    {
        $options = new KitOptions();

        self::assertSame('sp_', $options->savepointPrefix);
        self::assertSame(600, $options->lockTtl);
        self::assertSame(substr(gethostname() . ':' . getmypid(), 0, 64), $options->owner());
        self::assertSame('worker-7', (new KitOptions(lockOwner: 'worker-7'))->owner());
        self::assertSame(64, \strlen((new KitOptions(lockOwner: str_repeat('x', 100)))->owner()));
    }

    public function testASavepointPrefixThatIsNotAnIdentifierIsRefused(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new KitOptions(savepointPrefix: 'sp-1');
    }
}
