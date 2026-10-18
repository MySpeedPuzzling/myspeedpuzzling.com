<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Services;

use SpeedPuzzling\Web\Services\TimezoneChoices;
use SpeedPuzzling\Web\Value\TimezoneIdentifier;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class TimezoneChoicesTest extends KernelTestCase
{
    public function testEveryListedZoneIsOfferedOnceInItsRegion(): void
    {
        self::bootKernel();
        $choices = self::getContainer()->get(TimezoneChoices::class)->grouped('en');

        $offered = array_merge(...array_values(array_map('array_values', $choices)));
        sort($offered);
        $listed = TimezoneIdentifier::all();
        sort($listed);

        self::assertSame($listed, $offered);
        self::assertSame(['Europe', 'Americas', 'Asia', 'Africa', 'Australia', 'Pacific', 'Atlantic', 'Indian Ocean', 'Antarctica', 'Arctic', 'Other'], array_keys($choices));
        self::assertSame('Europe/Prague', $choices['Europe']['Central European Time (Prague)']);
        self::assertContains('UTC', $choices['Other']);
    }

    public function testNamesComeFromIcuInThePageLanguageSortedByCity(): void
    {
        self::bootKernel();
        $choices = self::getContainer()->get(TimezoneChoices::class)->grouped('cs');

        self::assertArrayHasKey('Evropa', $choices);
        self::assertSame('Europe/Prague', $choices['Evropa']['středoevropský čas (Praha)']);

        $european = array_values($choices['Evropa']);
        self::assertLessThan(array_search('Europe/Vienna', $european, true), array_search('Europe/Amsterdam', $european, true), 'Amsterdam before Vídeň');
    }

    public function testNormalizeKeepsListedZonesAndMapsOldNames(): void
    {
        self::assertSame('Europe/Prague', TimezoneIdentifier::normalize(' Europe/Prague '));
        self::assertSame('UTC', TimezoneIdentifier::normalize('UTC'));
        self::assertSame('Europe/Kyiv', TimezoneIdentifier::normalize('Europe/Kiev'));
        self::assertNull(TimezoneIdentifier::normalize('Mars/Base'));
        self::assertNull(TimezoneIdentifier::normalize(42));
        self::assertNull(TimezoneIdentifier::normalize(null));
    }
}
