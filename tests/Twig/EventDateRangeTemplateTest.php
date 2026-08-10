<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Twig;

use DateTimeImmutable;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Twig\Environment;

final class EventDateRangeTemplateTest extends KernelTestCase
{
    private Environment $twig;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->twig = self::getContainer()->get(Environment::class);
    }

    private function render(DateTimeImmutable $from, null|DateTimeImmutable $to): string
    {
        return trim($this->twig->render('_event_date_range.html.twig', [
            'date_from' => $from,
            'date_to' => $to,
        ]));
    }

    public function testSameMonthRangeShowsDayOnlyForStart(): void
    {
        $year = (int) date('Y');

        self::assertSame('09.-11.10.', $this->render(
            new DateTimeImmutable("{$year}-10-09"),
            new DateTimeImmutable("{$year}-10-11"),
        ));
    }

    public function testCrossMonthRangeShowsMonthForBothDates(): void
    {
        $year = (int) date('Y');

        self::assertSame('01.01.-31.12.', $this->render(
            new DateTimeImmutable("{$year}-01-01"),
            new DateTimeImmutable("{$year}-12-31"),
        ));
    }

    public function testSingleDayShowsDayAndMonth(): void
    {
        $year = (int) date('Y');

        self::assertSame('23.05.', $this->render(
            new DateTimeImmutable("{$year}-05-23"),
            new DateTimeImmutable("{$year}-05-23"),
        ));
    }

    public function testDifferentYearAppendsYear(): void
    {
        $year = (int) date('Y') + 1;

        self::assertSame("02.-03.07.{$year}", $this->render(
            new DateTimeImmutable("{$year}-07-02"),
            new DateTimeImmutable("{$year}-07-03"),
        ));
    }

    public function testMissingEndDateFallsBackToStartDate(): void
    {
        $year = (int) date('Y');

        self::assertSame('23.05.', $this->render(new DateTimeImmutable("{$year}-05-23"), null));
    }
}
