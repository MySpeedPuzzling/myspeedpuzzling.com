<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Value;

use DateTimeImmutable;
use DateTimeZone;
use PHPUnit\Framework\TestCase;
use SpeedPuzzling\Web\Value\RoundTimezone;

final class RoundTimezoneTest extends TestCase
{
    public function testStoredTimezoneWins(): void
    {
        self::assertSame('America/Chicago', RoundTimezone::resolve('America/Chicago', 'us'));
    }

    public function testWithoutStoredTimezoneTheCountrysDefaultIsUsed(): void
    {
        self::assertSame('America/New_York', RoundTimezone::resolve(null, 'us'));
        self::assertSame('Europe/Vienna', RoundTimezone::resolve(null, 'at'));
        self::assertSame(RoundTimezone::FALLBACK, RoundTimezone::resolve(null, null));
        // An edition without a country of its own: its series' country
        self::assertSame('America/Toronto', RoundTimezone::resolve(null, null, 'ca'));
        self::assertSame(RoundTimezone::FALLBACK, RoundTimezone::resolve('Not/AZone', null));
    }

    public function testZoneIsAssumedOnlyWhenNothingDecidesIt(): void
    {
        // An online series without a country, a round saved before zones were kept
        self::assertTrue(RoundTimezone::isAssumed(null, null, null));
        self::assertTrue(RoundTimezone::isAssumed('Not/AZone', null, 'xx'));
        self::assertTrue(RoundTimezone::isAssumed(null));

        // Saved as the fallback the form pre-selects for an event without a country - most likely never chosen
        self::assertTrue(RoundTimezone::isAssumed('Europe/Prague', null, null));

        self::assertFalse(RoundTimezone::isAssumed('America/Toronto', null, null));
        self::assertFalse(RoundTimezone::isAssumed('Europe/Prague', 'cz', null));
        self::assertFalse(RoundTimezone::isAssumed(null, 'cz', null));
        self::assertFalse(RoundTimezone::isAssumed(null, null, 'ca'));
    }

    public function testLocalTimeBecomesTheInstantAcrossDaylightSaving(): void
    {
        // Chicago is UTC-5 in October (CDT), UTC-6 in November (CST)
        self::assertSame('2026-10-24T15:05:00+00:00', RoundTimezone::toInstant('2026-10-24 10:05', 'America/Chicago')->format('c'));
        self::assertSame('2026-11-07T16:05:00+00:00', RoundTimezone::toInstant('2026-11-07 10:05', 'America/Chicago')->format('c'));
    }

    public function testInstantIsShownAsTheLocalTimeTyped(): void
    {
        $instant = new DateTimeImmutable('2026-10-24 15:05:00', new DateTimeZone('UTC'));

        self::assertSame('2026-10-24 10:05', RoundTimezone::toLocal($instant, 'America/Chicago')->format('Y-m-d H:i'));
    }
}
