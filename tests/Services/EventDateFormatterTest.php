<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Services;

use DateTimeImmutable;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use SpeedPuzzling\Web\Services\EventDateFormatter;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Translation\IdentityTranslator;

final class EventDateFormatterTest extends TestCase
{
    /**
     * @return iterable<string, array{string, string, null|string, string}>
     */
    public static function provideDates(): iterable
    {
        yield 'en one day' => ['en', '2026-10-24 10:00', '2026-10-24 18:00', 'Oct 24'];
        yield 'en no end' => ['en', '2026-10-24 10:00', null, 'Oct 24'];
        yield 'en same month' => ['en', '2026-10-10 09:00', '2026-10-11 17:00', 'Oct 10–11'];
        yield 'en across months' => ['en', '2026-10-30 09:00', '2026-11-02 17:00', 'Oct 30 – Nov 2'];
        yield 'en next year' => ['en', '2027-01-10 09:00', null, 'Jan 10, 2027'];
        yield 'fr same month, day first' => ['fr', '2026-10-10 09:00', '2026-10-11 17:00', '10–11 oct.'];
        yield 'cs one day' => ['cs', '2026-10-24 10:00', null, '24. 10.'];
        yield 'ja same month' => ['ja', '2026-10-10 09:00', '2026-10-11 17:00', '10月10日–11日'];
    }

    #[DataProvider('provideDates')]
    public function testCompactDays(string $locale, string $from, null|string $to, string $expected): void
    {
        $formatter = new EventDateFormatter(new MockClock(new DateTimeImmutable('2026-10-03 12:00:00')), new IdentityTranslator());

        self::assertSame($expected, $formatter->format(
            new DateTimeImmutable($from),
            $to !== null ? new DateTimeImmutable($to) : null,
            $locale,
        ));
    }

    public function testThePageLocaleByDefault(): void
    {
        $translator = new IdentityTranslator();
        $translator->setLocale('de');
        $formatter = new EventDateFormatter(new MockClock(new DateTimeImmutable('2026-10-03 12:00:00')), $translator);

        self::assertSame('24. Okt.', $formatter->format(new DateTimeImmutable('2026-10-24 10:00')));
    }
}
