<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Services;

use DateTimeImmutable;
use DateTimeZone;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use SpeedPuzzling\Web\Services\ZonedDateTimeFormatter;
use SpeedPuzzling\Web\Value\RoundTimezone;
use Symfony\Component\Translation\LocaleSwitcher;

final class ZonedDateTimeFormatterTest extends TestCase
{
    public function testAKnownZoneIsNamedByItsPlace(): void
    {
        $formatter = $this->formatter('en');

        self::assertSame('Chicago Time', $formatter->timezoneName('America/Chicago'));
        self::assertSame('Czechia Time', $formatter->timezoneName('Europe/Prague'));
    }

    /**
     * An online series without a country: nobody said the event is in Czechia, the zone is only the fallback
     */
    #[DataProvider('provideAssumedZoneNames')]
    public function testAnAssumedZoneIsNamedWithoutAPlace(string $locale, string $expected): void
    {
        self::assertSame($expected, $this->formatter($locale)->timezoneName(RoundTimezone::FALLBACK, assumed: true));
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function provideAssumedZoneNames(): iterable
    {
        yield 'en' => ['en', 'Central European Time'];
        yield 'cs' => ['cs', 'středoevropský čas'];
        yield 'de' => ['de', 'Mitteleuropäische Zeit'];
        yield 'es' => ['es', 'hora de Europa central'];
        yield 'fr' => ['fr', 'heure d’Europe centrale'];
        yield 'ja' => ['ja', '中央ヨーロッパ時間'];
    }

    public function testFormattedMomentCarriesTheZoneName(): void
    {
        $moment = new DateTimeImmutable('2026-10-24 13:05:00', new DateTimeZone('UTC'));
        $formatter = $this->formatter('en');

        self::assertStringEndsWith('(Czechia Time)', $formatter->format($moment, 'Europe/Prague'));
        self::assertStringEndsWith('(Central European Time)', $formatter->format($moment, 'Europe/Prague', assumed: true));
        self::assertStringContainsString('3:05', $formatter->format($moment, 'Europe/Prague', assumed: true));
    }

    private function formatter(string $locale): ZonedDateTimeFormatter
    {
        return new ZonedDateTimeFormatter(new LocaleSwitcher($locale, []));
    }
}
