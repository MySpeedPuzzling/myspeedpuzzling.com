<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Services\Wjpf;

use PHPUnit\Framework\TestCase;
use SpeedPuzzling\Web\Services\Wjpf\WjpfPairingCodeStore;
use Symfony\Component\Cache\Adapter\ArrayAdapter;

final class WjpfPairingCodeStoreTest extends TestCase
{
    private const string PLAYER_ID = '018d0000-0000-0000-0000-000000000001';

    public function testIssuedCodeResolvesToThePlayer(): void
    {
        $store = new WjpfPairingCodeStore(new ArrayAdapter());

        $code = $store->issue(self::PLAYER_ID);

        self::assertSame(self::PLAYER_ID, $store->consume($code));
    }

    /** A replayed code must never resolve twice - that is the whole point of it being a code. */
    public function testCodeCannotBeRedeemedTwice(): void
    {
        $store = new WjpfPairingCodeStore(new ArrayAdapter());
        $code = $store->issue(self::PLAYER_ID);

        $store->consume($code);

        self::assertNull($store->consume($code));
    }

    public function testUnknownCodeResolvesToNull(): void
    {
        $store = new WjpfPairingCodeStore(new ArrayAdapter());

        self::assertNull($store->consume('not-a-real-code'));
    }

    public function testEachIssueProducesADistinctCode(): void
    {
        $store = new WjpfPairingCodeStore(new ArrayAdapter());

        self::assertNotSame($store->issue(self::PLAYER_ID), $store->issue(self::PLAYER_ID));
    }

    /** A cache dump must not yield anything redeemable. */
    public function testCodeIsNotStoredVerbatim(): void
    {
        $cache = new ArrayAdapter();
        $store = new WjpfPairingCodeStore($cache);

        $code = $store->issue(self::PLAYER_ID);

        foreach (array_keys($cache->getValues()) as $key) {
            self::assertStringNotContainsString($code, (string) $key);
        }
    }

    public function testCodeIsUrlSafe(): void
    {
        $store = new WjpfPairingCodeStore(new ArrayAdapter());

        self::assertMatchesRegularExpression('/^[A-Za-z0-9_-]+$/', $store->issue(self::PLAYER_ID));
    }
}
