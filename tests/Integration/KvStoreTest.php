<?php

declare(strict_types=1);

namespace TrilbyMedia\GravDbKit\Tests\Integration;

use PHPUnit\Framework\TestCase;
use TrilbyMedia\GravDbKit\Database\Connection;
use TrilbyMedia\GravDbKit\Support\KvStore;
use TrilbyMedia\GravDbKit\Support\UnsubscribeSigner;
use TrilbyMedia\GravDbKit\Testing\FrozenClock;
use TrilbyMedia\GravDbKit\Tests\Support\TestEngine;

final class KvStoreTest extends TestCase
{
    private Connection $db;
    private KvStore $kv;

    protected function setUp(): void
    {
        $this->db = TestEngine::migrated();
        $this->kv = new KvStore($this->db, null, new FrozenClock(500));
    }

    public function testSetGetOverwriteAndDelete(): void
    {
        self::assertNull($this->kv->get('site_base_url'));

        $this->kv->set('site_base_url', 'https://example.test');
        $this->kv->set('site_base_url', 'https://example.org');
        self::assertSame('https://example.org', $this->kv->get('site_base_url'));
        self::assertSame(500, (int)$this->db->fetchValue("SELECT updated_at FROM kit_kv WHERE kv_key = 'site_base_url'"));

        $this->kv->set('site_base_url', null);
        self::assertNull($this->kv->get('site_base_url'));

        $this->kv->delete('site_base_url');
        self::assertSame(0, (int)$this->db->fetchValue('SELECT COUNT(*) FROM kit_kv'));
    }

    /** A value that must exist exactly once is created once and never replaced by a later caller's. */
    public function testRememberKeepsTheFirstValue(): void
    {
        $calls = 0;
        $create = static function () use (&$calls): string {
            return 'secret-' . ++$calls;
        };

        self::assertSame('secret-1', $this->kv->remember('unsubscribe_secret', $create));
        self::assertSame('secret-1', $this->kv->remember('unsubscribe_secret', $create));
        self::assertSame(1, $calls);
    }

    /** Two requests that both found nothing agree on whichever value landed first. */
    public function testRememberLosingARaceReturnsTheWinnersValue(): void
    {
        // Another request's store; one connection stands in for two, the order of writes is what matters.
        $other = new KvStore($this->db);

        $value = $this->kv->remember('channel_secret', static function () use ($other): string {
            // The other request writes its value between our read and our write.
            $other->set('channel_secret', 'theirs');

            return 'ours';
        });

        self::assertSame('theirs', $value);
        self::assertSame('theirs', $this->kv->get('channel_secret'));
    }

    public function testRememberFillsARowThatHoldsNull(): void
    {
        $this->kv->set('k', null);

        self::assertSame('made', $this->kv->remember('k', static fn (): string => 'made'));
        self::assertSame('made', $this->kv->get('k'));
    }

    public function testAKeyLongerThanTheColumnIsRefusedRatherThanCut(): void
    {
        $this->kv->set(str_repeat('k', 64), 'fits');
        self::assertSame('fits', $this->kv->get(str_repeat('k', 64)));

        $this->expectException(\InvalidArgumentException::class);
        $this->kv->set(str_repeat('k', 65), 'too long');
    }

    // ------------------------------------------------------ unsubscribe links

    public function testASignedLinkVerifiesAndCannotBeRetargeted(): void
    {
        $signer = new UnsubscribeSigner($this->kv);
        $token = $signer->sign('42', 'replies');

        self::assertSame(64, \strlen($token));
        self::assertTrue($signer->verify('42', 'replies', $token));
        self::assertFalse($signer->verify('43', 'replies', $token), 'another person');
        self::assertFalse($signer->verify('42', 'all', $token), 'another list');
        self::assertFalse($signer->verify('42', 'replies', ''), 'no token');
        self::assertFalse($signer->verify('42', 'rep|lies', $token));
    }

    /** The secret is created once and shared, so a link signed by one request verifies in the next. */
    public function testTheSecretLivesInTheKvStore(): void
    {
        $token = (new UnsubscribeSigner($this->kv))->sign('person@example.test', 'all');

        $secret = $this->kv->get(UnsubscribeSigner::SECRET_KEY);
        self::assertSame(64, \strlen((string)$secret));
        self::assertTrue((new UnsubscribeSigner(new KvStore($this->db)))->verify('person@example.test', 'all', $token));
    }

    /**
     * Forum Pro signed `{userId}|{scope}` with the same HMAC and the same KV
     * key, so links already in members' inboxes keep working after the switch.
     */
    public function testForumProLinksStillVerify(): void
    {
        $this->kv->set('unsubscribe_secret', 'forum-installation-secret');
        $forumToken = hash_hmac('sha256', 17 . '|' . 'watched', 'forum-installation-secret');

        $signer = new UnsubscribeSigner($this->kv);
        self::assertTrue($signer->verify((string)17, 'watched', $forumToken));
        self::assertSame($forumToken, $signer->sign('17', 'watched'));
    }

    public function testAScopeMayNotContainTheSeparator(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        (new UnsubscribeSigner($this->kv))->sign('a', 'b|c');
    }
}
