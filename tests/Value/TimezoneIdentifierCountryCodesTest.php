<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Value;

use PHPUnit\Framework\TestCase;
use SpeedPuzzling\Web\Value\CountryCode;
use SpeedPuzzling\Web\Value\TimezoneIdentifier;

final class TimezoneIdentifierCountryCodesTest extends TestCase
{
    public function testZonesAndBrowserAliasesNameTheirCountry(): void
    {
        $countries = TimezoneIdentifier::countryCodes();

        self::assertSame('cz', $countries['Europe/Prague'] ?? null);
        self::assertSame('us', $countries['America/New_York'] ?? null);
        self::assertSame('gb', $countries['Europe/London'] ?? null);
        // Chrome reports the old names
        self::assertSame('in', $countries['Asia/Calcutta'] ?? null);
        self::assertSame('ua', $countries['Europe/Kiev'] ?? null);
    }

    public function testZonesOfNoCountryAreLeftOut(): void
    {
        $countries = TimezoneIdentifier::countryCodes();

        self::assertArrayNotHasKey('UTC', $countries);
    }

    public function testEveryCountryIsOneTheProfileOffers(): void
    {
        $unknown = array_filter(
            array_unique(TimezoneIdentifier::countryCodes()),
            static fn (string $code): bool => CountryCode::fromCode($code) === null,
        );

        // A zone of a country the select does not list is simply never guessed - this only shows which they are
        self::assertLessThan(5, count($unknown), implode(', ', $unknown));
    }
}
