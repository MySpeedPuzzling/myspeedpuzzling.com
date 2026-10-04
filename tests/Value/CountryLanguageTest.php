<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Value;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ReflectionClassConstant;
use SpeedPuzzling\Web\Value\CountryCode;
use SpeedPuzzling\Web\Value\CountryLanguage;
use Symfony\Component\Intl\Languages;

final class CountryLanguageTest extends TestCase
{
    #[DataProvider('countries')]
    public function testLanguageOfACountry(null|string $country, null|string $expected): void
    {
        self::assertSame($expected, CountryLanguage::of($country));
    }

    /**
     * @return iterable<string, array{null|string, null|string}>
     */
    public static function countries(): iterable
    {
        yield 'Czechia' => ['cz', 'cs'];
        yield 'Austria reads German' => ['at', 'de'];
        yield 'Brazil reads Portuguese' => ['br', 'pt'];
        yield 'Japan' => ['jp', 'ja'];
        yield 'Norway reads Bokmål' => ['no', 'nb'];
        yield 'stored upper case' => ['CZ', 'cs'];
        yield 'United Kingdom: English is no second line' => ['gb', null];
        yield 'United States' => ['us', null];
        yield 'Ireland' => ['ie', null];
        yield 'Canada: two languages' => ['ca', null];
        yield 'Switzerland: several languages' => ['ch', null];
        yield 'Belgium: several languages' => ['be', null];
        yield 'Luxembourg: several languages' => ['lu', null];
        yield 'India: no single language' => ['in', null];
        yield 'no country' => [null, null];
        yield 'unknown code' => ['xx', null];
    }

    /**
     * Every language of the map is one Symfony Intl names ("Also known as") and every country one a profile can hold
     */
    public function testEveryMappedCountryAndLanguageExists(): void
    {
        foreach (CountryCode::cases() as $country) {
            $language = CountryLanguage::of($country->name);

            if ($language !== null) {
                self::assertTrue(Languages::exists($language), sprintf('%s => %s', $country->name, $language));
            }
        }

        $reflection = new ReflectionClassConstant(CountryLanguage::class, 'LANGUAGES');
        /** @var array<string, string> $map */
        $map = $reflection->getValue();

        foreach (array_keys($map) as $code) {
            self::assertNotNull(CountryCode::fromCode($code), $code);
        }
    }
}
