<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Query;

use Doctrine\DBAL\Connection;
use Psr\Clock\ClockInterface;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Exceptions\PuzzleNotFound;
use SpeedPuzzling\Web\Query\GetPuzzleSummary;
use SpeedPuzzling\Web\Results\CompetitionReference;
use SpeedPuzzling\Web\Tests\DataFixtures\CompetitionFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\CompetitionRoundFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\CompetitionSeriesFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\PuzzleFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\TagFixture;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Public facts of the puzzle page summary: puzzle_statistics plus the publicly visible competitions the puzzle
 * was used at (by tag or by round), in one query.
 */
final class GetPuzzleSummaryTest extends KernelTestCase
{
    private GetPuzzleSummary $query;
    private Connection $database;

    protected function setUp(): void
    {
        self::bootKernel();

        $this->query = self::getContainer()->get(GetPuzzleSummary::class);
        $this->database = self::getContainer()->get(Connection::class);
    }

    public function testStatisticsAreSplitBySoloPairsAndTeams(): void
    {
        // PUZZLE_1000_01: eight solo solves (fastest 3900 s) and one pair solve (3600 s)
        $summary = $this->query->forPuzzle(PuzzleFixture::PUZZLE_1000_01);

        self::assertSame(8, $summary->soloSolvesCount);
        self::assertSame(3900, $summary->fastestTimeSolo);
        self::assertNotNull($summary->medianTimeSolo);
        self::assertGreaterThan(3900, $summary->medianTimeSolo);
        self::assertSame(1, $summary->duoSolvesCount);
        self::assertSame(3600, $summary->fastestTimeDuo);
        self::assertSame(0, $summary->teamSolvesCount);
        self::assertNull($summary->fastestTimeTeam);

        self::assertTrue($summary->hasSoloTimes());
        self::assertFalse($summary->hasSingleSoloTime());
        self::assertTrue($summary->hasDuoTimes());
        self::assertFalse($summary->hasTeamTimes());
        self::assertTrue($summary->hasGroupTimes());
    }

    public function testNeverSolvedPuzzleHasNoTimes(): void
    {
        // PUZZLE_4000 has no puzzle_statistics row at all
        $summary = $this->query->forPuzzle(PuzzleFixture::PUZZLE_4000);

        self::assertSame(0, $summary->soloSolvesCount);
        self::assertNull($summary->medianTimeSolo);
        self::assertNull($summary->fastestTimeSolo);
        self::assertSame(0, $summary->duoSolvesCount);
        self::assertSame(0, $summary->teamSolvesCount);
        self::assertFalse($summary->hasSoloTimes());
        self::assertFalse($summary->hasGroupTimes());
        self::assertSame([], $summary->usedAt);
    }

    public function testPairOnlyPuzzleHasGroupTimesButNoSoloTimes(): void
    {
        // PUZZLE_1000_03: a single pair solve, nobody solved it alone
        $summary = $this->query->forPuzzle(PuzzleFixture::PUZZLE_1000_03);

        self::assertFalse($summary->hasSoloTimes());
        self::assertTrue($summary->hasDuoTimes());
        self::assertTrue($summary->hasGroupTimes());
    }

    public function testSolvesLoggedWithoutTimeGiveNoSoloTimes(): void
    {
        $this->database->executeStatement(
            'UPDATE puzzle_statistics SET median_time_solo = NULL, fastest_time_solo = NULL WHERE puzzle_id = :puzzleId',
            ['puzzleId' => PuzzleFixture::PUZZLE_500_03],
        );

        $summary = $this->query->forPuzzle(PuzzleFixture::PUZZLE_500_03);

        self::assertGreaterThan(0, $summary->soloSolvesCount);
        self::assertFalse($summary->hasSoloTimes());
        self::assertFalse($summary->hasSingleSoloTime());
    }

    public function testSingleSoloTimeWhenTheMedianEqualsTheFastestTime(): void
    {
        // PUZZLE_1500_02: one solo solve so far
        $summary = $this->query->forPuzzle(PuzzleFixture::PUZZLE_1500_02);

        self::assertSame(1, $summary->soloSolvesCount);
        self::assertSame($summary->fastestTimeSolo, $summary->medianTimeSolo);
        self::assertTrue($summary->hasSingleSoloTime());
    }

    public function testCompetitionsFromRoundsAreListedOldestFirst(): void
    {
        // PUZZLE_500_01 is in a WJPC 2024 round (in 30 days) and a Czech Nationals 2024 round (in 60 days)
        $usedAt = $this->query->forPuzzle(PuzzleFixture::PUZZLE_500_01)->usedAt;

        self::assertSame(['WJPC 2024', 'Czech National Championship 2024'], $this->displayNames($usedAt));
        self::assertSame('event_detail', $usedAt[0]->routeName());
        self::assertSame(['slug' => 'wjpc-2024'], $usedAt[0]->routeParameters());
        self::assertSame(['slug' => 'czech-nationals-2024'], $usedAt[1]->routeParameters());
    }

    public function testCompetitionOfATagIsListed(): void
    {
        $this->tagPuzzle(TagFixture::TAG_WJPC, PuzzleFixture::PUZZLE_1000_04);

        $usedAt = $this->query->forPuzzle(PuzzleFixture::PUZZLE_1000_04)->usedAt;

        self::assertSame(['WJPC 2024'], $this->displayNames($usedAt));
    }

    public function testCompetitionFoundByTagAndByRoundIsListedOnce(): void
    {
        $this->tagPuzzle(TagFixture::TAG_WJPC, PuzzleFixture::PUZZLE_500_01);

        $usedAt = $this->query->forPuzzle(PuzzleFixture::PUZZLE_500_01)->usedAt;

        self::assertSame(['WJPC 2024', 'Czech National Championship 2024'], $this->displayNames($usedAt));
    }

    public function testCompetitionsThatAreNotPubliclyVisibleAreLeftOut(): void
    {
        // An unapproved standalone event, an edition of an unapproved series and an unapproved series itself
        $this->database->executeStatement(
            'UPDATE competition SET tag_id = :tagId WHERE id IN (:unapproved, :unapprovedEdition)',
            [
                'tagId' => TagFixture::TAG_ONLINE,
                'unapproved' => CompetitionFixture::COMPETITION_UNAPPROVED,
                'unapprovedEdition' => CompetitionSeriesFixture::EDITION_UNAPPROVED_1,
            ],
        );
        $this->database->executeStatement(
            'UPDATE competition_series SET tag_id = :tagId WHERE id = :seriesId',
            ['tagId' => TagFixture::TAG_ONLINE, 'seriesId' => CompetitionSeriesFixture::SERIES_UNAPPROVED],
        );
        $this->tagPuzzle(TagFixture::TAG_ONLINE, PuzzleFixture::PUZZLE_1000_04);

        self::assertSame([], $this->query->forPuzzle(PuzzleFixture::PUZZLE_1000_04)->usedAt);
    }

    public function testEditionOfASeriesLinksToTheEditionPage(): void
    {
        $this->database->executeStatement(
            'UPDATE competition SET tag_id = :tagId WHERE id = :editionId',
            ['tagId' => TagFixture::TAG_ONLINE, 'editionId' => CompetitionSeriesFixture::EDITION_EJJ_68],
        );
        $this->tagPuzzle(TagFixture::TAG_ONLINE, PuzzleFixture::PUZZLE_1000_04);

        $usedAt = $this->query->forPuzzle(PuzzleFixture::PUZZLE_1000_04)->usedAt;

        self::assertSame(['Euro Jigsaw Jam · EJJ #68 — February 2026'], $this->displayNames($usedAt));
        self::assertSame('edition_detail', $usedAt[0]->routeName());
        self::assertSame(
            ['seriesSlug' => 'euro-jigsaw-jam-series', 'editionSlug' => 'ejj-68-february-2026'],
            $usedAt[0]->routeParameters(),
        );
    }

    public function testTagOfAWholeSeriesLinksToTheSeriesPageAfterTheEvents(): void
    {
        // A tag moves to the series when its competition is converted into one
        $this->database->executeStatement(
            'UPDATE competition_series SET tag_id = :tagId WHERE id = :seriesId',
            ['tagId' => TagFixture::TAG_ONLINE, 'seriesId' => CompetitionSeriesFixture::SERIES_OFFLINE],
        );
        $this->tagPuzzle(TagFixture::TAG_ONLINE, PuzzleFixture::PUZZLE_500_01);

        $usedAt = $this->query->forPuzzle(PuzzleFixture::PUZZLE_500_01)->usedAt;

        self::assertSame(['WJPC 2024', 'Czech National Championship 2024', 'Puzzle Meetup Prague'], $this->displayNames($usedAt));
        self::assertTrue($usedAt[2]->isSeries);
        self::assertSame('competition_series_detail', $usedAt[2]->routeName());
        self::assertSame(['slug' => 'puzzle-meetup-prague'], $usedAt[2]->routeParameters());
    }

    public function testRoundPuzzleHiddenUntilItsRoundStartsStaysOutUntilRevealed(): void
    {
        // The organizer keeps PUZZLE_1000_04 secret until the WJPC final round (in 32 days) starts
        $this->database->executeStatement(
            "INSERT INTO competition_round_puzzle (id, round_id, puzzle_id, hide_until_round_starts, hide_mode) VALUES (:id, :roundId, :puzzleId, true, 'entirely')",
            [
                'id' => Uuid::uuid7()->toString(),
                'roundId' => CompetitionRoundFixture::ROUND_WJPC_FINAL,
                'puzzleId' => PuzzleFixture::PUZZLE_1000_04,
            ],
        );

        self::assertSame([], $this->query->forPuzzle(PuzzleFixture::PUZZLE_1000_04)->usedAt);

        // Revealed the round's reveal delay (the default 10 minutes) after the start, as on the event page
        $this->moveRoundStart(CompetitionRoundFixture::ROUND_WJPC_FINAL, '-5 minutes');
        self::assertSame([], $this->query->forPuzzle(PuzzleFixture::PUZZLE_1000_04)->usedAt);

        $this->moveRoundStart(CompetitionRoundFixture::ROUND_WJPC_FINAL, '-15 minutes');
        self::assertSame(['WJPC 2024'], $this->displayNames($this->query->forPuzzle(PuzzleFixture::PUZZLE_1000_04)->usedAt));
    }

    public function testEmbargoedPuzzleIsNotListedByItsRounds(): void
    {
        $this->database->executeStatement(
            "UPDATE puzzle SET hide_until = '2099-12-31' WHERE id = :puzzleId",
            ['puzzleId' => PuzzleFixture::PUZZLE_500_02],
        );

        // PUZZLE_500_02 is in the WJPC 2024 qualification round
        self::assertSame([], $this->query->forPuzzle(PuzzleFixture::PUZZLE_500_02)->usedAt);
    }

    public function testUnknownPuzzleThrows(): void
    {
        $this->expectException(PuzzleNotFound::class);

        $this->query->forPuzzle(Uuid::uuid7()->toString());
    }

    public function testInvalidPuzzleIdThrows(): void
    {
        $this->expectException(PuzzleNotFound::class);

        $this->query->forPuzzle('not-a-uuid');
    }

    private function tagPuzzle(string $tagId, string $puzzleId): void
    {
        $this->database->executeStatement(
            'INSERT INTO tag_puzzle (tag_id, puzzle_id) VALUES (:tagId, :puzzleId)',
            ['tagId' => $tagId, 'puzzleId' => $puzzleId],
        );
    }

    private function moveRoundStart(string $roundId, string $relativeToNow): void
    {
        $startsAt = self::getContainer()->get(ClockInterface::class)->now()->modify($relativeToNow);

        $this->database->executeStatement(
            'UPDATE competition_round SET starts_at = :startsAt WHERE id = :roundId',
            ['startsAt' => $startsAt->format('Y-m-d H:i:s'), 'roundId' => $roundId],
        );
    }

    /**
     * @param list<CompetitionReference> $competitions
     * @return list<string>
     */
    private function displayNames(array $competitions): array
    {
        return array_map(static fn (CompetitionReference $competition): string => $competition->displayName(), $competitions);
    }
}
