<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\DatePrecision;

use Doctrine\DBAL\Connection;
use SpeedPuzzling\Web\Entity\Player;
use SpeedPuzzling\Web\Query\GetDuplicateCandidates;
use SpeedPuzzling\Web\Query\GetExportableSolvingTimes;
use SpeedPuzzling\Web\Query\GetMostActivePlayers;
use SpeedPuzzling\Web\Query\GetPlayerActivityCalendar;
use SpeedPuzzling\Web\Query\GetPlayerChartData;
use SpeedPuzzling\Web\Query\GetPlayerSolvedPuzzles;
use SpeedPuzzling\Web\Query\GetPlayerStatsSnapshot;
use SpeedPuzzling\Web\Query\GetRecentActivity;
use SpeedPuzzling\Web\Results\DuplicateCandidate;
use SpeedPuzzling\Web\Results\MostActivePlayer;
use SpeedPuzzling\Web\Results\PlayerChartData;
use SpeedPuzzling\Web\Services\PuzzleIntelligence\PlayerBaselineCalculator;
use SpeedPuzzling\Web\Tests\MessageHandler\DataImport\ImportAddingScenario;
use SpeedPuzzling\Web\Tests\SeriesEditionScenario;
use SpeedPuzzling\Web\Value\ChartTimePeriodType;
use SpeedPuzzling\Web\Value\DatePrecision;
use SpeedPuzzling\Web\Value\Statistics\PerCategoryStatistics;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * What each reader does with a result that knows only its month or year
 * (docs/features/data-import/date-precision.md, the table of readers): such a result stands on the first day of its
 * period - here 1 June 2024, a Saturday, and 1 January 2023, a Sunday - and that day is never taken as a day.
 */
final class DatePrecisionReadersTest extends KernelTestCase
{
    use ImportAddingScenario;

    private Player $player;
    private string $playerId;

    /** @var array<string, string> name => puzzle id */
    private array $puzzles = [];

    protected function setUp(): void
    {
        self::bootKernel();

        $this->player = $this->seedPairingPlayer('precision-readers');
        $this->playerId = $this->player->id->toString();
        $brand = $this->seedBrand('Zqreaders');

        foreach (['Zq Readers Month', 'Zq Readers Year', 'Zq Readers Day'] as $name) {
            $this->puzzles[$name] = $this->seedCataloguePuzzle($name, 500, $brand);
        }

        // From a spreadsheet: "June 2024" and "2023". Typed by hand: Saturday 8 June 2024
        [$import, $sheet] = $this->importOf($this->player);
        $this->resultRow($import, $sheet, 'Zq Readers Month', 500, 'Zqreaders', '2024-06', 3000);
        $this->resultRow($import, $sheet, 'Zq Readers Year', 500, 'Zqreaders', '2023', 3100);
        $this->pair($import);
        $this->addAndWork($import, $this->player);
        $this->typedResult($this->player, $this->puzzles['Zq Readers Day'], '2024-06-08', 3200);

        self::assertSame(
            ['year', 'month', null],
            array_map(static fn (array $result): mixed => $result['finished_at_precision'], $this->resultsOf($this->player)),
        );
    }

    public function testTheCalendarHasOnlyRealDays(): void
    {
        $calendar = self::getContainer()->get(GetPlayerActivityCalendar::class);

        self::assertSame(['2024-06-08'], array_keys($calendar->perDayInMonth($this->playerId, 2024, 6)));
        self::assertSame(['2024-06-08'], $calendar->activeDaysInMonth($this->playerId, 2024, 6));
        // One Saturday - not two: the first of June is a Saturday only in the database
        self::assertSame([0, 0, 0, 0, 0, 1, 0], array_values($calendar->dayOfWeekBucketsInMonth($this->playerId, 2024, 6)));
        self::assertSame([], $calendar->activeDaysInMonth($this->playerId, 2023, 1));
    }

    public function testStreaksWeekendsAndQuartersOfTheAchievements(): void
    {
        $snapshot = self::getContainer()->get(GetPlayerStatsSnapshot::class)->forPlayer($this->playerId);

        // Every result counts as a result
        self::assertSame(3, $snapshot->distinctPuzzlesSolved);
        // A weekday needs a day: only 8 June 2024 is a weekend solve
        self::assertSame(1, $snapshot->weekendSolves);
        // A streak is made of days: one day
        self::assertSame(1, $snapshot->allTimeLongestStreakDays);
        // "June 2024" is in a quarter (the second of 2024), "2023" in none: one quarter, not two
        self::assertSame(1, $snapshot->steadyHandsQuarters);
    }

    public function testTheStatisticsCountTheResultAndNoDayOfIt(): void
    {
        $results = self::getContainer()->get(GetPlayerSolvedPuzzles::class)->soloByPlayerId($this->playerId);
        $statistics = new PerCategoryStatistics($results);

        self::assertCount(3, $results);
        self::assertSame(['2024-06-08'], $statistics->activeDays);

        $precisions = [];

        foreach ($results as $result) {
            $precisions[$result->puzzleName] = $result->datePrecision;
        }

        self::assertSame(DatePrecision::Month, $precisions['Zq Readers Month']);
        self::assertSame(DatePrecision::Year, $precisions['Zq Readers Year']);
        self::assertSame(DatePrecision::Day, $precisions['Zq Readers Day']);
    }

    /**
     * The qualifying first attempt streams from `custom_pst_qualifying_first_attempt`, keyed on the stored solve
     * moment: a result known to its month or year is ordered as the first day of that period (date-precision.md
     * "Which solve was first") - and the statement keeps the index's own sort key.
     */
    public function testWhichSolveWasFirstIsOrderedByTheStoredFirstDayOfAPeriod(): void
    {
        // The same puzzle on 8 June 2024 by hand: "June 2024" is ordered as 1 June - before it. "2023" before 2024
        $this->typedResult($this->player, $this->puzzles['Zq Readers Month'], '2024-06-08', 2900);
        $this->typedResult($this->player, $this->puzzles['Zq Readers Year'], '2024-06-09', 2800);

        $first = [];

        foreach (['Zq Readers Month', 'Zq Readers Year'] as $name) {
            [$sql, $parameters, $types] = self::getContainer()->get(PlayerBaselineCalculator::class)->qualifyingFirstAttemptsQuery([$this->playerId], $this->puzzles[$name]);
            self::assertStringContainsString('ORDER BY pst.first_attempt DESC, COALESCE(pst.finished_at, pst.tracked_at) ASC, pst.tracked_at ASC, pst.id ASC', $sql);
            self::assertStringNotContainsString('finished_at_precision', $sql);
            $rows = self::getContainer()->get(Connection::class)->fetchAllAssociative($sql, $parameters, $types);
            self::assertCount(1, $rows);
            $first[$name] = $rows[0]['seconds_to_solve'];
        }

        self::assertSame(3000, $first['Zq Readers Month']);
        self::assertSame(3100, $first['Zq Readers Year']);
    }

    public function testTheSameDayOfTheDuplicateRulesIsNeverTheFirstDayOfAPeriod(): void
    {
        // Typed for 1 June 2024 with the time of the "June 2024" result: the same time, not "the same day"
        $this->typedResult($this->player, $this->puzzles['Zq Readers Month'], '2024-06-01', 3000);
        self::assertSame([], $this->duplicatePairs());

        // The proof that only the precision keeps them apart: two results of 1 June with one time are a pair
        $this->pairingDatabase()->executeStatement('UPDATE puzzle_solving_time SET finished_at_precision = NULL WHERE player_id = :id', ['id' => $this->playerId]);
        $pairs = $this->duplicatePairs();
        self::assertCount(1, $pairs);
        self::assertTrue($pairs[0]->sameDay());
    }

    public function testTheFeedAndTheExportSayHowMuchOfTheDateIsKnown(): void
    {
        $feed = [];

        foreach (self::getContainer()->get(GetRecentActivity::class)->forPlayer($this->playerId, 10) as $item) {
            $feed[$item->puzzleName] = $item->datePrecision;
        }

        self::assertSame(DatePrecision::Month, $feed['Zq Readers Month']);
        self::assertSame(DatePrecision::Year, $feed['Zq Readers Year']);
        self::assertSame(DatePrecision::Day, $feed['Zq Readers Day']);

        $exported = [];

        foreach (self::getContainer()->get(GetExportableSolvingTimes::class)->byPlayerId($this->playerId) as $row) {
            $columns = $row->toArray();
            self::assertIsString($columns['puzzle_name']);
            $exported[$columns['puzzle_name']] = [$columns['finished_at'], $columns['date_precision']];
        }

        // The date column keeps its value - the new column says what it is
        self::assertSame(['2024-06-01 00:00:00', 'month'], $exported['Zq Readers Month']);
        self::assertSame(['2023-01-01 00:00:00', 'year'], $exported['Zq Readers Year']);
        self::assertSame(['2024-06-08 00:00:00', 'day'], $exported['Zq Readers Day']);
    }

    public function testWeeksAndMonthsOfTheChartsAndOfTheMostActive(): void
    {
        $chart = self::getContainer()->get(GetPlayerChartData::class);

        // Per week: only the result of 8 June 2024 is in a week (the one that starts on Monday 3 June)
        self::assertSame(['2024-06-03'], self::periods($chart->getForPlayer($this->playerId, null, ChartTimePeriodType::Week, 500, false)));
        // Per month: "June 2024" is in June 2024 with it; "2023" is in no month
        $months = $chart->getForPlayer($this->playerId, null, ChartTimePeriodType::Month, 500, false);
        self::assertSame(['2024-06-01'], self::periods($months));
        self::assertSame((int) round((3000 + 3200) / 2), $months[0]->time);

        // "Most active of the month": the month result is of June 2024, the year result of no January
        $mostActive = self::getContainer()->get(GetMostActivePlayers::class);

        self::assertSame(2, $this->solvedBy($mostActive->mostActiveSoloPlayersInMonth(1000, 6, 2024)));
        self::assertSame(0, $this->solvedBy($mostActive->mostActiveSoloPlayersInMonth(1000, 1, 2023)));
    }

    /**
     * @param array<PlayerChartData> $points
     *
     * @return list<string>
     */
    private static function periods(array $points): array
    {
        return array_values(array_map(static fn (PlayerChartData $point): string => $point->period->format('Y-m-d'), $points));
    }

    /**
     * @param array<MostActivePlayer> $players
     */
    private function solvedBy(array $players): int
    {
        foreach ($players as $player) {
            if ($player->playerId === $this->playerId) {
                return $player->solvedPuzzlesCount;
            }
        }

        return 0;
    }

    /**
     * @phpstan-impure
     *
     * @return list<DuplicateCandidate>
     */
    private function duplicatePairs(): array
    {
        return self::getContainer()->get(GetDuplicateCandidates::class)->ofPeopleOnPuzzle($this->puzzles['Zq Readers Month'], [$this->playerId]);
    }

    public function testAnEditionOfASeriesIsNeverFoundByTheFirstDayOfAPeriod(): void
    {
        $scenario = new SeriesEditionScenario(self::getContainer());
        $series = $scenario->series();
        $edition = $scenario->edition($series, 'Jam No. 1', '2026-03-01');
        $puzzle = $scenario->puzzle();
        self::assertNotNull($this->player->userId);

        // A series pick solved on 1 March 2026: the day finds the edition
        $timeId = $scenario->addTime($this->player->userId, $puzzle, '2026-03-01', seriesId: $series);
        self::assertSame([$edition, 'date'], [$scenario->link($timeId)['competition_id'], $scenario->link($timeId)['series_edition_match']]);

        // The same result knowing only "March 2026": its stored 1 March is no day an edition is matched by - the
        // result is the series' own (series-level), which is a normal, permanent state
        $this->pairingDatabase()->executeStatement("UPDATE puzzle_solving_time SET finished_at_precision = 'month' WHERE id = :id", ['id' => $timeId]);
        $scenario->reconcile();

        self::assertSame([null, null, $series], [$scenario->link($timeId)['competition_id'], $scenario->link($timeId)['series_edition_match'], $scenario->link($timeId)['competition_series_id']]);
    }
}
