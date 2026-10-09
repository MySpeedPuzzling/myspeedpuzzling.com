<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Query;

use Doctrine\DBAL\Connection;
use Psr\Clock\ClockInterface;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Exceptions\PuzzleNotFound;
use SpeedPuzzling\Web\Message\RevealRoundPuzzleNow;
use SpeedPuzzling\Web\Query\GetPuzzleSummary;
use SpeedPuzzling\Web\Results\PuzzleSummary;
use SpeedPuzzling\Web\Results\PuzzleUsedAtLine;
use SpeedPuzzling\Web\Tests\DataFixtures\CompetitionFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\CompetitionRoundFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\CompetitionSeriesFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\EventsPageFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\OrganizationFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\PuzzleFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\TagFixture;
use SpeedPuzzling\Web\Tests\SeriesEditionScenario;
use SpeedPuzzling\Web\Value\RoundCategory;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Public facts of the puzzle page summary: puzzle_statistics plus where the puzzle was used, in one query - "Used at"
 * lines (docs/features/events-page/high-frequency-series.md P24): the rounds of publicly visible events holding the
 * revealed puzzle, newest first and at most 10, then the tags no round line names.
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

    public function testRoundLinesAreListedNewestFirst(): void
    {
        // PUZZLE_500_01 is in a Czech Nationals 2024 round (in 60 days), a WJPC 2024 round (in 30 days) and a Moonlight
        // Sprint League round (60 days ago, EventDetailFixture) - solo rounds
        $summary = $this->query->forPuzzle(PuzzleFixture::PUZZLE_500_01);
        $usedAt = $summary->usedAt;

        self::assertSame(['Czech National Championship 2024', 'WJPC 2024', 'Moonlight Sprint League · Season One'], $this->displayNames($usedAt));
        self::assertSame(0, $summary->usedAtMore);
        self::assertSame([true, true, true], array_map(static fn (PuzzleUsedAtLine $line): bool => $line->isRound(), $usedAt));
        self::assertSame(
            [RoundCategory::Solo, RoundCategory::Solo, RoundCategory::Solo],
            array_map(static fn (PuzzleUsedAtLine $line): null|RoundCategory => $line->category, $usedAt),
        );

        self::assertSame('event_detail', $usedAt[0]->routeName());
        self::assertSame(['slug' => 'czech-nationals-2024', '_fragment' => 'round-' . CompetitionRoundFixture::ROUND_CZECH_FINAL], $usedAt[0]->routeParameters());
        self::assertSame(['slug' => 'wjpc-2024', '_fragment' => 'round-' . CompetitionRoundFixture::ROUND_WJPC_QUALIFICATION], $usedAt[1]->routeParameters());
        self::assertSame('edition_detail', $usedAt[2]->routeName());
        self::assertSame(
            ['seriesSlug' => 'moonlight-sprint-league', 'editionSlug' => 'season-one', '_fragment' => 'round-' . EventsPageFixture::ROUND_SPRINT_1],
            $usedAt[2]->routeParameters(),
        );
        self::assertNotNull($usedAt[1]->startsAt);
        self::assertGreaterThan($usedAt[2]->startsAt, $usedAt[1]->startsAt);
    }

    public function testCompetitionOfATagIsListed(): void
    {
        $this->tagPuzzle(TagFixture::TAG_WJPC, PuzzleFixture::PUZZLE_1000_04);

        $usedAt = $this->query->forPuzzle(PuzzleFixture::PUZZLE_1000_04)->usedAt;

        self::assertSame(['WJPC 2024'], $this->displayNames($usedAt));
        // A tag line: no round, no date - the event's page without an anchor
        self::assertFalse($usedAt[0]->isRound());
        self::assertNull($usedAt[0]->startsAt);
        self::assertSame(['slug' => 'wjpc-2024'], $usedAt[0]->routeParameters());
    }

    public function testCompetitionFoundByTagAndByRoundIsListedOnce(): void
    {
        $this->tagPuzzle(TagFixture::TAG_WJPC, PuzzleFixture::PUZZLE_500_01);

        $usedAt = $this->query->forPuzzle(PuzzleFixture::PUZZLE_500_01)->usedAt;

        // The round line names WJPC 2024 - no tag line for it
        self::assertSame(['Czech National Championship 2024', 'WJPC 2024', 'Moonlight Sprint League · Season One'], $this->displayNames($usedAt));
        self::assertTrue($usedAt[1]->isRound());
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

    /**
     * Drafts (docs/features/organizations/README.md "Drafts"): a draft series behind a tag, a draft event using the
     * puzzle in a round - listed only once published
     */
    public function testDraftsAreLeftOut(): void
    {
        $this->database->executeStatement(
            'UPDATE competition_series SET tag_id = :tagId, is_draft = true WHERE id = :seriesId',
            ['tagId' => TagFixture::TAG_ONLINE, 'seriesId' => CompetitionSeriesFixture::SERIES_OFFLINE],
        );
        $this->tagPuzzle(TagFixture::TAG_ONLINE, PuzzleFixture::PUZZLE_3000);

        // PUZZLE_3000 is in the round of the draft Birchwood night - and in no other round
        self::assertSame([], $this->query->forPuzzle(PuzzleFixture::PUZZLE_3000)->usedAt);

        $this->database->executeStatement(
            'UPDATE competition_series SET is_draft = false WHERE id = :seriesId',
            ['seriesId' => CompetitionSeriesFixture::SERIES_OFFLINE],
        );
        $this->database->executeStatement(
            'UPDATE competition SET is_draft = false WHERE id = :competitionId',
            ['competitionId' => OrganizationFixture::COMPETITION_DRAFT_NIGHT],
        );

        $published = $this->query->forPuzzle(PuzzleFixture::PUZZLE_3000);
        self::assertSame([OrganizationFixture::COMPETITION_DRAFT_NIGHT_NAME, 'Puzzle Meetup Prague'], $this->displayNames($published->usedAt));
        // The night's round, then the series' tag
        self::assertCount(1, $published->usedAtRounds());
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

        self::assertSame(['Czech National Championship 2024', 'WJPC 2024', 'Moonlight Sprint League · Season One', 'Puzzle Meetup Prague'], $this->displayNames($usedAt));
        self::assertTrue($usedAt[3]->isSeries);
        self::assertFalse($usedAt[3]->isRound());
        self::assertSame('competition_series_detail', $usedAt[3]->routeName());
        self::assertSame(['slug' => 'puzzle-meetup-prague'], $usedAt[3]->routeParameters());
    }

    public function testRoundOfAnEditionLinksToTheEditionPageAtTheRound(): void
    {
        $scenario = new SeriesEditionScenario(self::getContainer());
        $puzzleId = $scenario->puzzle();
        $editionId = $scenario->edition($scenario->series(), 'Jam No. 154', '2026-10-07');
        $roundId = $scenario->round($editionId, RoundCategory::Duo, '2026-10-07 19:00', puzzleIds: [$puzzleId]);

        $usedAt = $this->query->forPuzzle($puzzleId)->usedAt;

        self::assertSame(['Lantern Weekly Jam · Jam No. 154'], $this->displayNames($usedAt));
        self::assertSame(RoundCategory::Duo, $usedAt[0]->category);
        self::assertSame('2026-10-07', $usedAt[0]->startsAt?->format('Y-m-d'));
        self::assertSame('edition_detail', $usedAt[0]->routeName());
        self::assertSame(
            [...$this->editionSlugs($editionId), '_fragment' => 'round-' . $roundId],
            $usedAt[0]->routeParameters(),
        );
    }

    /**
     * A round starting late in the evening in Toronto starts the next day in UTC - the line names the local day
     */
    public function testTheDayIsTheRoundsLocalDay(): void
    {
        $scenario = new SeriesEditionScenario(self::getContainer());
        $puzzleId = $scenario->puzzle();
        $editionId = $scenario->edition($scenario->series(), 'Jam No. 155', '2026-10-08');
        $scenario->round($editionId, RoundCategory::Solo, '2026-10-08 23:30', 'America/Toronto', [$puzzleId]);

        $startsAt = $this->query->forPuzzle($puzzleId)->usedAt[0]->startsAt;

        self::assertNotNull($startsAt);
        self::assertSame('2026-10-08 23:30', $startsAt->format('Y-m-d H:i'));
    }

    public function testAtMostTenRoundLinesTheRestCounted(): void
    {
        $scenario = new SeriesEditionScenario(self::getContainer());
        $puzzleId = $scenario->puzzle();
        $seriesId = $scenario->series();

        for ($jam = 1; $jam <= 12; $jam++) {
            $day = sprintf('2026-09-%02d', $jam);
            $scenario->round($scenario->edition($seriesId, 'Jam No. ' . $jam, $day), RoundCategory::Solo, $day . ' 19:00', puzzleIds: [$puzzleId]);
        }

        $summary = $this->query->forPuzzle($puzzleId);

        self::assertCount(PuzzleSummary::USED_AT_ROUNDS_LIMIT, $summary->usedAt);
        self::assertSame(2, $summary->usedAtMore);
        // Newest first: jams 12 down to 3, the two oldest are "and 2 more"
        self::assertSame('Lantern Weekly Jam · Jam No. 12', $summary->usedAt[0]->displayName());
        self::assertSame('Lantern Weekly Jam · Jam No. 3', $summary->usedAt[9]->displayName());
        self::assertCount(PuzzleSummary::USED_AT_ROUNDS_LIMIT, $summary->usedAtRounds());
    }

    /**
     * H12 scenario 4: a round that keeps its puzzle secret never names it - until it is revealed
     */
    public function testSecretRoundIsLeftOutUntilRevealed(): void
    {
        $scenario = new SeriesEditionScenario(self::getContainer());
        $puzzleId = $scenario->puzzle();
        $seriesId = $scenario->series();
        $scenario->round($scenario->edition($seriesId, 'Jam No. 160', '2026-09-20'), RoundCategory::Solo, '2026-09-20 19:00', puzzleIds: [$puzzleId]);
        $secretRoundId = $scenario->round($scenario->edition($seriesId, 'Jam No. 161', '2026-09-22'), RoundCategory::Duo, '2026-09-22 19:00', puzzleIds: [$puzzleId], secret: true);

        $summary = $this->query->forPuzzle($puzzleId);
        self::assertSame(['Lantern Weekly Jam · Jam No. 160'], $this->displayNames($summary->usedAt));
        self::assertSame(0, $summary->usedAtMore, 'A secret round is not even counted');

        $scenario->dispatch(new RevealRoundPuzzleNow($scenario->roundPuzzleId($secretRoundId, $puzzleId)));

        self::assertSame(
            ['Lantern Weekly Jam · Jam No. 161', 'Lantern Weekly Jam · Jam No. 160'],
            $this->displayNames($this->query->forPuzzle($puzzleId)->usedAt),
        );
    }

    public function testRoundsOfDraftsAreLeftOut(): void
    {
        $scenario = new SeriesEditionScenario(self::getContainer());
        $puzzleId = $scenario->puzzle();
        $draftEditionId = $scenario->edition($scenario->series(), 'Jam No. 170', '2026-09-24', draft: true);
        $scenario->round($draftEditionId, RoundCategory::Solo, '2026-09-24 19:00', puzzleIds: [$puzzleId]);
        $draftSeriesEditionId = $scenario->edition($scenario->series('Moonlit Draft Sprints', draft: true), 'Sprint 1', '2026-09-25');
        $scenario->round($draftSeriesEditionId, RoundCategory::Solo, '2026-09-25 19:00', puzzleIds: [$puzzleId]);

        $summary = $this->query->forPuzzle($puzzleId);

        self::assertSame([], $summary->usedAt);
        self::assertSame(0, $summary->usedAtMore);
    }

    /**
     * A series tag a round line already names (a round of one of its editions) adds no second line
     */
    public function testSeriesTagNamedByARoundLineOfItsEditionIsNotRepeated(): void
    {
        $scenario = new SeriesEditionScenario(self::getContainer());
        $puzzleId = $scenario->puzzle();
        $seriesId = $scenario->series();
        $scenario->round($scenario->edition($seriesId, 'Jam No. 180', '2026-09-26'), RoundCategory::Team, '2026-09-26 19:00', puzzleIds: [$puzzleId]);

        $this->database->executeStatement(
            'UPDATE competition_series SET tag_id = :tagId WHERE id = :seriesId',
            ['tagId' => TagFixture::TAG_ONLINE, 'seriesId' => $seriesId],
        );
        $this->tagPuzzle(TagFixture::TAG_ONLINE, $puzzleId);

        self::assertSame(['Lantern Weekly Jam · Jam No. 180'], $this->displayNames($this->query->forPuzzle($puzzleId)->usedAt));
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
     * @param list<PuzzleUsedAtLine> $lines
     * @return list<string>
     */
    private function displayNames(array $lines): array
    {
        return array_map(static fn (PuzzleUsedAtLine $line): string => $line->displayName(), $lines);
    }

    /**
     * @return array{seriesSlug: string, editionSlug: string}
     */
    private function editionSlugs(string $editionId): array
    {
        /** @var array{series_slug: string, edition_slug: string} $row */
        $row = $this->database->fetchAssociative(
            'SELECT cs.slug AS series_slug, c.slug AS edition_slug FROM competition c INNER JOIN competition_series cs ON cs.id = c.series_id WHERE c.id = :id',
            ['id' => $editionId],
        );

        return ['seriesSlug' => $row['series_slug'], 'editionSlug' => $row['edition_slug']];
    }
}
