<?php

declare(strict_types=1);

namespace TrilbyMedia\GravDbKit\Tests\Unit;

use PHPUnit\Framework\TestCase;
use TrilbyMedia\GravDbKit\Database\SchemaGuard;

final class SchemaGuardPolicyTest extends TestCase
{
    public function testSqliteOnlyIsAnAliasOfSqlite(): void
    {
        self::assertSame('sqlite', SchemaGuard::normalizePolicy('sqlite-only'));
        self::assertSame('sqlite', SchemaGuard::normalizePolicy('sqlite'));
        self::assertSame('auto', SchemaGuard::normalizePolicy('auto'));
        self::assertSame('manual', SchemaGuard::normalizePolicy('manual'));
    }

    public function testWhichEnginesEachPolicyMigrates(): void
    {
        foreach (['sqlite', 'mysql', 'pgsql'] as $engine) {
            self::assertTrue(SchemaGuard::allows('auto', $engine));
            self::assertFalse(SchemaGuard::allows('manual', $engine));
            self::assertSame($engine === 'sqlite', SchemaGuard::allows('sqlite', $engine));
            self::assertSame($engine === 'sqlite', SchemaGuard::allows('sqlite-only', $engine));
        }
    }

    public function testAnUnknownPolicyIsRefusedRatherThanReadAsManual(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        SchemaGuard::normalizePolicy('sqlite_only');
    }
}
