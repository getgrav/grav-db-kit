<?php

declare(strict_types=1);

namespace TrilbyMedia\GravDbKit\Tests\Unit\Jobs;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use TrilbyMedia\GravDbKit\Jobs\JobHandler;
use TrilbyMedia\GravDbKit\Jobs\JobHandlerRegistry;

/**
 * Registration runs inside an event every job-owning add-on listens to, and the
 * queue it feeds carries the emails somebody is waiting on. Nothing a badly
 * packaged add-on does there is allowed to cost the site a handler it needs.
 */
final class JobHandlerRegistryTest extends TestCase
{
    public function testRegistersAndReturnsAHandler(): void
    {
        $registry = new JobHandlerRegistry();
        $handler = $this->handler();

        $registry->register('order.confirmation_email', $handler);

        self::assertSame($handler, $registry->get('order.confirmation_email'));
        self::assertSame(['order.confirmation_email'], $registry->types());
    }

    public function testUnknownTypeReturnsNull(): void
    {
        self::assertNull((new JobHandlerRegistry())->get('nope'));
    }

    /**
     * The owning plugin registers first, so this is what stops an add-on
     * quietly taking over `order.confirmation_email` and swallowing every
     * receipt.
     */
    public function testFirstRegistrationOfATypeWins(): void
    {
        $logged = [];
        $registry = new JobHandlerRegistry(static function (string $message) use (&$logged): void {
            $logged[] = $message;
        });

        $first = $this->handler();
        $second = $this->handler();

        $registry->register('order.confirmation_email', $first);
        $registry->register('order.confirmation_email', $second);

        self::assertSame($first, $registry->get('order.confirmation_email'));
        self::assertCount(1, $logged);
        self::assertStringContainsString('duplicate job type', $logged[0]);
        self::assertStringContainsString('order.confirmation_email', $logged[0]);
    }

    #[DataProvider('malformedTypes')]
    public function testMalformedTypesAreDroppedAndLogged(string $type): void
    {
        $logged = [];
        $registry = new JobHandlerRegistry(static function (string $message) use (&$logged): void {
            $logged[] = $message;
        });

        $registry->register($type, $this->handler());

        self::assertSame([], $registry->types());
        self::assertCount(1, $logged);
        self::assertStringContainsString('invalid job type', $logged[0]);
    }

    /** @return array<string, array{0: string}> */
    public static function malformedTypes(): array
    {
        return [
            'empty' => [''],
            'uppercase' => ['Order.Confirmation'],
            'leading dot' => ['.order'],
            'leading dash' => ['-order'],
            'space' => ['order confirmation'],
            'slash' => ['order/confirmation'],
        ];
    }

    /** Existing types already use both dots and underscores. */
    public function testDottedAndUnderscoredTypesAreAccepted(): void
    {
        $registry = new JobHandlerRegistry();

        $registry->register('order.confirmation_email', $this->handler());
        $registry->register('stock.release_expired', $this->handler());
        $registry->register('licensing.issue', $this->handler());

        self::assertSame(
            ['order.confirmation_email', 'stock.release_expired', 'licensing.issue'],
            $registry->types()
        );
    }

    public function testRegistrationNeverThrows(): void
    {
        $registry = new JobHandlerRegistry();

        $registry->register('!!bad!!', $this->handler());
        $registry->register('fine', $this->handler());
        $registry->register('fine', $this->handler());

        self::assertSame(['fine'], $registry->types());
    }

    public function testAllIsKeyedByType(): void
    {
        $registry = new JobHandlerRegistry();
        $handler = $this->handler();

        $registry->register('licensing.issue', $handler);

        self::assertSame(['licensing.issue' => $handler], $registry->all());
    }

    /** A logger that throws is not a reason for registration to. */
    public function testALoggerThatThrowsDoesNotMakeRegistrationThrow(): void
    {
        $registry = new JobHandlerRegistry(static function (): void {
            throw new \RuntimeException('log is read-only');
        });

        $registry->register('Bad Type', $this->handler());

        self::assertSame([], $registry->types());
    }

    private function handler(): JobHandler
    {
        return new class implements JobHandler {
            public function handle(array $payload): void
            {
            }
        };
    }
}
