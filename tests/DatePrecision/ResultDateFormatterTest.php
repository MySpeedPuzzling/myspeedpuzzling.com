<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\DatePrecision;

use DateTimeImmutable;
use DateTimeZone;
use PHPUnit\Framework\Attributes\DataProvider;
use SpeedPuzzling\Web\Services\ResultDateFormatter;
use SpeedPuzzling\Web\Value\DatePrecision;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Contracts\Translation\LocaleAwareInterface;
use Twig\Environment;

/**
 * THE date of a result (docs/features/data-import/date-precision.md "Showing"): a day, a month or a year, in every
 * language - and a day written exactly as the templates wrote it before precision existed.
 */
final class ResultDateFormatterTest extends KernelTestCase
{
    /**
     * @return iterable<string, array{string, string, string, string, string, string}>
     */
    public static function locales(): iterable
    {
        //                  day, numeric    day, medium        month, numeric  month, medium     year
        yield 'en' => ['en', '15.03.2021', 'Mar 15, 2021', 'Mar 2021', 'March 2021', '2021'];
        yield 'cs' => ['cs', '15.03.2021', '15. 3. 2021', 'březen 2021', 'březen 2021', '2021'];
        yield 'de' => ['de', '15.03.2021', '15.03.2021', 'März 2021', 'März 2021', '2021'];
        yield 'es' => ['es', '15.03.2021', '15 mar 2021', 'mar 2021', 'marzo de 2021', '2021'];
        yield 'fr' => ['fr', '15.03.2021', '15 mars 2021', 'mars 2021', 'mars 2021', '2021'];
        yield 'ja' => ['ja', '15.03.2021', '2021/03/15', '2021年3月', '2021年3月', '2021年'];
    }

    #[DataProvider('locales')]
    public function testADayAMonthAndAYearInEveryLanguage(string $locale, string $dayNumeric, string $dayMedium, string $monthNumeric, string $monthMedium, string $year): void
    {
        self::bootKernel();
        $formatter = self::getContainer()->get(ResultDateFormatter::class);
        // As stored: a day at midnight; a month and a year on their first day
        $day = new DateTimeImmutable('2021-03-15 00:00:00', new DateTimeZone('UTC'));
        $month = new DateTimeImmutable('2021-03-01 00:00:00', new DateTimeZone('UTC'));
        $january = new DateTimeImmutable('2021-01-01 00:00:00', new DateTimeZone('UTC'));

        $nbsp = static fn (string $text): string => str_replace(["\u{00A0}", "\u{202F}"], ' ', $text);

        self::assertSame($dayNumeric, $formatter->format($day, DatePrecision::Day, ResultDateFormatter::STYLE_NUMERIC, $locale));
        self::assertSame($dayMedium, $nbsp($formatter->format($day, null, ResultDateFormatter::STYLE_MEDIUM, $locale)));
        self::assertSame($monthNumeric, $nbsp($formatter->format($month, DatePrecision::Month, ResultDateFormatter::STYLE_NUMERIC, $locale)));
        self::assertSame($monthMedium, $nbsp($formatter->format($month, 'month', ResultDateFormatter::STYLE_MEDIUM, $locale)));
        self::assertSame($year, $formatter->format($january, DatePrecision::Year, ResultDateFormatter::STYLE_NUMERIC, $locale));
        self::assertSame($year, $formatter->format($january, 'year', ResultDateFormatter::STYLE_MEDIUM, $locale));

        // Never the first day a period is stored on
        self::assertStringNotContainsString('01', $formatter->format($month, DatePrecision::Month, ResultDateFormatter::STYLE_NUMERIC, $locale));
        self::assertSame('2021-03', ResultDateFormatter::machine($month, DatePrecision::Month));
        self::assertSame('2021', ResultDateFormatter::machine($january, DatePrecision::Year));
        self::assertSame('2021-03-15', ResultDateFormatter::machine($day));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function localeCodes(): iterable
    {
        foreach (['en', 'cs', 'de', 'es', 'fr', 'ja'] as $locale) {
            yield $locale => [$locale];
        }
    }

    /**
     * Nothing changes for a result that knows its day: `result_date()` prints what `|date('d.m.Y')` and
     * `|format_date('medium')` printed - also for a result saved with a time of day through the API, which Twig moves
     * into its own zone.
     */
    #[DataProvider('localeCodes')]
    public function testADayIsWrittenExactlyAsTheTemplatesWroteIt(string $locale): void
    {
        self::bootKernel();
        $twig = self::getContainer()->get(Environment::class);
        $translator = self::getContainer()->get(LocaleAwareInterface::class);
        $translator->setLocale($locale);
        \Locale::setDefault($locale);

        try {
            foreach (['2021-03-15 00:00:00', '2021-12-31 23:30:00', '2024-02-29 10:00:00'] as $moment) {
                $date = new DateTimeImmutable($moment, new DateTimeZone('UTC'));

                self::assertSame(
                    $twig->createTemplate("{{ date|date('d.m.Y') }}|{{ date|format_date('medium') }}|{{ date|date('Y-m-d') }}")->render(['date' => $date]),
                    $twig->createTemplate("{{ result_date(date) }}|{{ result_date(date, null, 'medium') }}|{{ result_date_machine(date) }}")->render(['date' => $date]),
                    $locale . ' ' . $moment,
                );
            }

            $twigMonth = $twig->createTemplate("{{ result_date(date, precision) }}")->render(['date' => new DateTimeImmutable('2021-03-01'), 'precision' => DatePrecision::Month]);
            self::assertStringContainsString('2021', $twigMonth);
            self::assertStringNotContainsString('01', $twigMonth);
        } finally {
            $translator->setLocale('en');
            \Locale::setDefault('en');
        }
    }
}
