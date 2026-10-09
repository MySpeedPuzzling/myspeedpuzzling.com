<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Query;

use Doctrine\DBAL\Connection;
use SpeedPuzzling\Web\Query\SeriesEditionDays;
use SpeedPuzzling\Web\Tests\SeriesEditionScenario;
use SpeedPuzzling\Web\Value\RoundCategory;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * The per-series day facts the add-time picker and API v1's series list read (docs/features/events-page/
 * high-frequency-series.md "The default list"): publicly visible editions only, an undated edition dated by its first
 * round.
 */
final class SeriesEditionDaysTest extends KernelTestCase
{
    public function testDayFactsOfThePubliclyVisibleEditions(): void
    {
        self::bootKernel();
        $scenario = new SeriesEditionScenario(self::getContainer());
        $series = $scenario->series();
        $scenario->edition($series, 'Jam No. 1', '2026-03-02');
        $scenario->edition($series, 'Jam No. 2', '2026-03-09');
        $scenario->edition($series, 'Jam No. 3', '2026-03-16');
        $scenario->edition($series, 'Jam No. 4', '2026-03-23');
        $byRound = $scenario->edition($series, 'Jam No. 5', null);
        $scenario->round($byRound, RoundCategory::Solo, '2026-03-30 19:00');
        $scenario->edition($series, 'Summer Special', null);
        $scenario->edition($series, 'Draft Jam', '2026-03-10', draft: true);

        // The draft on the 10th is not counted
        self::assertSame(
            ['edition_count' => 6, 'has_live' => false, 'last_past_day' => '2026-03-09', 'next_day' => '2026-03-16'],
            $this->facts($series, '2026-03-10'),
        );
        // Jam No. 2 is live on its day
        self::assertSame(
            ['edition_count' => 6, 'has_live' => true, 'last_past_day' => '2026-03-02', 'next_day' => '2026-03-16'],
            $this->facts($series, '2026-03-09'),
        );
        // Undated by date, dated by its round
        self::assertSame('2026-03-30', $this->facts($series, '2026-03-24')['next_day']);
        self::assertSame('2026-03-30', $this->facts($series, '2026-04-01')['last_past_day']);

        $empty = $scenario->series('Moonlit Puzzle Sprint');
        self::assertSame(
            ['edition_count' => 0, 'has_live' => false, 'last_past_day' => null, 'next_day' => null],
            $this->facts($empty, '2026-03-09'),
        );
    }

    /**
     * @return array{edition_count: int, has_live: bool, last_past_day: null|string, next_day: null|string}
     */
    private function facts(string $seriesId, string $today): array
    {
        $join = SeriesEditionDays::sqlJoin();

        /** @var array{edition_count: int, has_live: bool, last_past_day: null|string, next_day: null|string} $row */
        $row = self::getContainer()->get(Connection::class)->fetchAssociative(
            "SELECT sed.edition_count, sed.has_live, CAST(sed.last_past_day AS VARCHAR) AS last_past_day, CAST(sed.next_day AS VARCHAR) AS next_day FROM competition_series cs {$join} WHERE cs.id = :id",
            ['id' => $seriesId, 'today' => $today],
        );

        return $row;
    }
}
