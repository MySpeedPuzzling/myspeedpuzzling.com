<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Query;

use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Psr\Clock\ClockInterface;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Query\GetPlayerStatsSnapshot;
use SpeedPuzzling\Web\Services\ActivityCalendarStreakCalculator;
use SpeedPuzzling\Web\Services\Badges\AchievementFirstMetDates;
use SpeedPuzzling\Web\Tests\DataFixtures\CompetitionFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\EventsPageFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\OfficialResultsFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\OnlineRoundsFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\PlayerFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\PuzzleFixture;
use SpeedPuzzling\Web\Value\TimezoneIdentifier;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class GetPlayerStatsSnapshotTest extends KernelTestCase
{
    private GetPlayerStatsSnapshot $query;
    private Connection $database;

    protected function setUp(): void
    {
        self::bootKernel();
        $container = self::getContainer();

        $this->database = $container->get(Connection::class);
        $this->query = new GetPlayerStatsSnapshot(
            $this->database,
            new ActivityCalendarStreakCalculator($container->get(ClockInterface::class)),
            $container->get(ClockInterface::class),
        );
    }

    public function testReturnsSnapshotForPlayerWithSolveTimes(): void
    {
        $snapshot = $this->query->forPlayer(PlayerFixture::PLAYER_REGULAR);

        self::assertSame(PlayerFixture::PLAYER_REGULAR, $snapshot->playerId);
        self::assertGreaterThan(0, $snapshot->distinctPuzzlesSolved);
        self::assertGreaterThan(0, $snapshot->totalPiecesSolved);
        self::assertNotNull($snapshot->best500PieceSoloSeconds);
        self::assertGreaterThan(0, $snapshot->best500PieceSoloSeconds);
        self::assertGreaterThanOrEqual(0, $snapshot->allTimeLongestStreakDays);
        self::assertGreaterThanOrEqual(0, $snapshot->teamSolvesCount);
    }

    public function testReturnsZerosForNonExistentPlayer(): void
    {
        $snapshot = $this->query->forPlayer('00000000-0000-0000-0000-000000000099');

        self::assertSame(0, $snapshot->distinctPuzzlesSolved);
        self::assertSame(0, $snapshot->totalPiecesSolved);
        self::assertNull($snapshot->best500PieceSoloSeconds);
        self::assertSame(0, $snapshot->allTimeLongestStreakDays);
        self::assertSame(0, $snapshot->teamSolvesCount);
        self::assertSame(0, $snapshot->zenPuzzlerSolves);
        self::assertSame(0, $snapshot->firstTrySolves);
        self::assertSame(0, $snapshot->unboxedSolves);
        self::assertSame(0, $snapshot->brandExplorerManufacturers);
        self::assertSame(0, $snapshot->marathonerSolves);
        self::assertSame(0, $snapshot->photographerSolves);
        self::assertSame(0, $snapshot->steadyHandsQuarters);
        self::assertSame(0, $snapshot->librarianApprovedRequests);
        self::assertNull($snapshot->best1000PieceSoloSeconds);
        self::assertSame(0, $snapshot->weekendSolves);
        self::assertSame(0, $snapshot->catalogerApprovedPuzzles);
        self::assertSame(0, $snapshot->muscleMemorySolves);
        self::assertSame(0, $snapshot->muscleMemoryUnboxedSolves);
        self::assertSame(0, $snapshot->nightOwlSolves);
        self::assertSame(0, $snapshot->competitorEvents);
        self::assertSame(0, $snapshot->onlineCompetitorResults);
    }

    public function testBest500PieceSoloSecondsIsSmallestValue(): void
    {
        // PLAYER_REGULAR has multiple 500pc solo solves; verify we get the fastest
        $snapshot = $this->query->forPlayer(PlayerFixture::PLAYER_REGULAR);

        self::assertNotNull($snapshot->best500PieceSoloSeconds);
        // The fastest 500pc time in fixtures is 1700s (TIME_08) for PLAYER_REGULAR
        self::assertLessThanOrEqual(1800, $snapshot->best500PieceSoloSeconds);
    }

    public function testPiecesSolvedCountsAllParticipation(): void
    {
        // PLAYER_REGULAR has solo + team solves across multiple piece counts
        $snapshot = $this->query->forPlayer(PlayerFixture::PLAYER_REGULAR);

        // Multiple puzzles of 500, 1000, 1500, 2000 pieces = well over 10,000 total
        self::assertGreaterThan(5000, $snapshot->totalPiecesSolved);
    }

    public function testPlayerWithOnlySoloSolvesHasZeroTeamCount(): void
    {
        // PLAYER_WITH_FAVORITES has only solo solves in fixtures
        $snapshot = $this->query->forPlayer(PlayerFixture::PLAYER_WITH_FAVORITES);

        self::assertSame(0, $snapshot->teamSolvesCount);
    }

    public function testPlayerWithTeamSolvesCountsThem(): void
    {
        // PLAYER_REGULAR has at least TIME_12 and TIME_41 as team solves
        $snapshot = $this->query->forPlayer(PlayerFixture::PLAYER_REGULAR);

        self::assertGreaterThanOrEqual(2, $snapshot->teamSolvesCount);
    }

    public function testSolveCountersForRegularPlayer(): void
    {
        $snapshot = $this->query->forPlayer(PlayerFixture::PLAYER_REGULAR);

        // TIME_46_RELAX_NO_FINISHED_AT is the only solve without tracked seconds (the pair/team results the player was in all carry a time)
        self::assertSame(1, $snapshot->zenPuzzlerSolves);

        // first_attempt=true solves: TIME_01, 06, 09, 12, 13, 19, 21, 25, 28, 29, 36, 41
        // (TIME_07/08 are repeats, TIME_46 is not a first attempt)
        // + INTEL_TIME_13, 14, 15 from PuzzleIntelligenceFixture (INTEL_TIME_17 is a repeat)
        self::assertSame(15, $snapshot->firstTrySolves);

        // TIME_45_UNBOXED belongs to PLAYER_WITH_STRIPE, not PLAYER_REGULAR
        self::assertSame(0, $snapshot->unboxedSolves);

        // The player's puzzles come from Ravensburger and Trefl only
        self::assertSame(2, $snapshot->brandExplorerManufacturers);

        // TIME_25 on PUZZLE_2000 (2000 pieces) is the only 2000+ piece solve
        self::assertSame(1, $snapshot->marathonerSolves);

        // No fixture solve has a finished puzzle photo
        self::assertSame(0, $snapshot->photographerSolves);

        // 1000pc solo timed solves: TIME_19 (4500s) and TIME_29 (3950s);
        // TIME_12/TIME_41 are duo solves and TIME_46 has no time
        self::assertSame(3950, $snapshot->best1000PieceSoloSeconds);
    }

    public function testAPairResultATeammateLoggedCountsForEveryoneInIt(): void
    {
        $before = $this->query->forPlayer(PlayerFixture::PLAYER_WITH_FAVORITES);

        // Logged by PLAYER_ADMIN, PLAYER_WITH_FAVORITES only in the pair: relaxed (no time), first try, unboxed,
        // with a photo, 2000 pieces, finished on a Saturday
        $this->database->executeStatement(
            <<<SQL
INSERT INTO puzzle_solving_time
    (id, seconds_to_solve, player_id, puzzle_id, tracked_at, verified, team, finished_at, finished_puzzle_photo,
     first_attempt, unboxed, puzzlers_count, puzzling_type, suspicious, pieces_count_snapshot)
VALUES
    (:id, NULL, :trackerId, :puzzleId, '2026-10-12 12:00:00', true, :team, '2026-10-10 12:00:00', 'finished.jpg',
     true, true, 2, 'duo', false, 2000)
SQL,
            [
                'id' => Uuid::uuid7()->toString(),
                'trackerId' => PlayerFixture::PLAYER_ADMIN,
                'puzzleId' => PuzzleFixture::PUZZLE_2000,
                'team' => json_encode(['team_id' => null, 'puzzlers' => [
                    ['player_id' => PlayerFixture::PLAYER_ADMIN, 'player_name' => null],
                    ['player_id' => PlayerFixture::PLAYER_WITH_FAVORITES, 'player_name' => null],
                ]], JSON_THROW_ON_ERROR),
            ],
        );

        $after = $this->query->forPlayer(PlayerFixture::PLAYER_WITH_FAVORITES);

        self::assertSame($before->zenPuzzlerSolves + 1, $after->zenPuzzlerSolves);
        self::assertSame($before->firstTrySolves + 1, $after->firstTrySolves);
        self::assertSame($before->unboxedSolves + 1, $after->unboxedSolves);
        self::assertSame($before->marathonerSolves + 1, $after->marathonerSolves);
        self::assertSame($before->photographerSolves + 1, $after->photographerSolves);
        self::assertSame($before->weekendSolves + 1, $after->weekendSolves);
        self::assertSame($before->best1000PieceSoloSeconds, $after->best1000PieceSoloSeconds);

        // The history replay counts the same rows: it still ends at the snapshot, so the tiers can be dated
        self::assertNotNull(self::getContainer()->get(AchievementFirstMetDates::class)->forPlayer(PlayerFixture::PLAYER_WITH_FAVORITES));
    }

    public function testUnboxedSolvesCountedForPlayerWithStripe(): void
    {
        // TIME_45_UNBOXED is the only unboxed solve in fixtures
        $snapshot = $this->query->forPlayer(PlayerFixture::PLAYER_WITH_STRIPE);

        self::assertSame(1, $snapshot->unboxedSolves);
    }

    public function testLibrarianCountsOnlyApprovedRequests(): void
    {
        // PuzzleReportFixture: PLAYER_REGULAR reported 4 change requests (1 approved,
        // 1 rejected, 2 pending) and 1 pending merge request
        $regular = $this->query->forPlayer(PlayerFixture::PLAYER_REGULAR);
        self::assertSame(1, $regular->librarianApprovedRequests);

        // PLAYER_ADMIN approved/rejected them but never reported any
        $admin = $this->query->forPlayer(PlayerFixture::PLAYER_ADMIN);
        self::assertSame(0, $admin->librarianApprovedRequests);
    }

    public function testCatalogerCountsOnlyApprovedPuzzles(): void
    {
        // PLAYER_REGULAR only added PUZZLE_UNAPPROVED (approved = false)
        $regular = $this->query->forPlayer(PlayerFixture::PLAYER_REGULAR);
        self::assertSame(0, $regular->catalogerApprovedPuzzles);

        // PLAYER_ADMIN added 20 approved puzzles in PuzzleFixture + 6 in CompetitionApiFixture
        $admin = $this->query->forPlayer(PlayerFixture::PLAYER_ADMIN);
        self::assertSame(26, $admin->catalogerApprovedPuzzles);
    }

    public function testWeekendAndQuarterMetricsStayWithinSaneBoundsOnRelativeFixtures(): void
    {
        // Fixture solve dates are relative to "now", so the weekday/quarter distribution is
        // not deterministic — deterministic coverage lives in the fixed-date tests below.
        $snapshot = $this->query->forPlayer(PlayerFixture::PLAYER_REGULAR);

        // PLAYER_REGULAR owns 19 fixture solves in total
        self::assertGreaterThanOrEqual(0, $snapshot->weekendSolves);
        self::assertLessThanOrEqual(19, $snapshot->weekendSolves);

        // All fixture activity happens within the last ~45 days: it spans 1 quarter,
        // or 2 consecutive ones when it crosses a quarter boundary
        self::assertGreaterThanOrEqual(1, $snapshot->steadyHandsQuarters);
        self::assertLessThanOrEqual(2, $snapshot->steadyHandsQuarters);
    }

    public function testWeekendSolvesCountSaturdaysAndSundaysWithGarbageDateGuard(): void
    {
        $baseline = $this->query->forPlayer(PlayerFixture::PLAYER_WITH_FAVORITES)->weekendSolves;

        // Counted: finished on Saturday (finished_at wins over the Monday tracked_at)
        $this->insertSolve(
            PlayerFixture::PLAYER_WITH_FAVORITES,
            PuzzleFixture::PUZZLE_500_04,
            trackedAt: new DateTimeImmutable('2020-06-01 12:00:00'),
            finishedAt: new DateTimeImmutable('2020-06-06 12:00:00'),
        );

        // Counted: no finished_at, tracked on Sunday (falls back to tracked_at)
        $this->insertSolve(
            PlayerFixture::PLAYER_WITH_FAVORITES,
            PuzzleFixture::PUZZLE_500_04,
            trackedAt: new DateTimeImmutable('2020-06-07 12:00:00'),
            finishedAt: null,
        );

        // NOT counted: finished on Monday (even though tracked on Saturday)
        $this->insertSolve(
            PlayerFixture::PLAYER_WITH_FAVORITES,
            PuzzleFixture::PUZZLE_500_04,
            trackedAt: new DateTimeImmutable('2020-06-06 12:00:00'),
            finishedAt: new DateTimeImmutable('2020-06-08 12:00:00'),
        );

        // NOT counted: garbage year-0024 row (proleptic Saturday) is excluded by the
        // >= 2000-01-01 guard — without the guard this would count as a weekend solve
        $this->insertSolve(
            PlayerFixture::PLAYER_WITH_FAVORITES,
            PuzzleFixture::PUZZLE_500_04,
            trackedAt: new DateTimeImmutable('0024-06-08 12:00:00'),
            finishedAt: new DateTimeImmutable('0024-06-08 12:00:00'),
        );

        $snapshot = $this->query->forPlayer(PlayerFixture::PLAYER_WITH_FAVORITES);

        self::assertSame($baseline + 2, $snapshot->weekendSolves);
    }

    public function testSteadyHandsQuartersFindLongestConsecutiveRun(): void
    {
        // Isolated island: a single quarter far in the past
        $this->insertSolve(PlayerFixture::PLAYER_WITH_FAVORITES, PuzzleFixture::PUZZLE_500_04, trackedAt: new DateTimeImmutable('2015-06-15 12:00:00'));

        // Consecutive run: 2019Q4 → 2020Q4 = 5 quarters (with a duplicate inside 2020Q1)
        $this->insertSolve(PlayerFixture::PLAYER_WITH_FAVORITES, PuzzleFixture::PUZZLE_500_04, trackedAt: new DateTimeImmutable('2019-11-15 12:00:00'));
        $this->insertSolve(PlayerFixture::PLAYER_WITH_FAVORITES, PuzzleFixture::PUZZLE_500_04, trackedAt: new DateTimeImmutable('2020-02-10 12:00:00'));
        $this->insertSolve(PlayerFixture::PLAYER_WITH_FAVORITES, PuzzleFixture::PUZZLE_500_04, trackedAt: new DateTimeImmutable('2020-03-15 12:00:00'));
        $this->insertSolve(PlayerFixture::PLAYER_WITH_FAVORITES, PuzzleFixture::PUZZLE_500_04, trackedAt: new DateTimeImmutable('2020-05-10 12:00:00'));
        $this->insertSolve(PlayerFixture::PLAYER_WITH_FAVORITES, PuzzleFixture::PUZZLE_500_04, trackedAt: new DateTimeImmutable('2020-08-10 12:00:00'));
        $this->insertSolve(PlayerFixture::PLAYER_WITH_FAVORITES, PuzzleFixture::PUZZLE_500_04, trackedAt: new DateTimeImmutable('2020-11-10 12:00:00'));

        // The "now"-relative fixture solves form an island of at most 2 quarters — the
        // 5-quarter run above is the longest
        self::assertSame(5, $this->query->forPlayer(PlayerFixture::PLAYER_WITH_FAVORITES)->steadyHandsQuarters);

        // Team participation counts too: PLAYER_WITH_FAVORITES is only a team member on
        // this 2021Q1 solve owned by PLAYER_REGULAR — it extends the run to 6 quarters
        $this->insertSolve(
            PlayerFixture::PLAYER_REGULAR,
            PuzzleFixture::PUZZLE_500_04,
            trackedAt: new DateTimeImmutable('2021-02-10 12:00:00'),
            team: [PlayerFixture::PLAYER_REGULAR, PlayerFixture::PLAYER_WITH_FAVORITES],
        );

        self::assertSame(6, $this->query->forPlayer(PlayerFixture::PLAYER_WITH_FAVORITES)->steadyHandsQuarters);

        // Garbage year-0024 rows are excluded by the >= 2000-01-01 guard
        $this->insertSolve(PlayerFixture::PLAYER_WITH_FAVORITES, PuzzleFixture::PUZZLE_500_04, trackedAt: new DateTimeImmutable('0024-05-05 12:00:00'));

        self::assertSame(6, $this->query->forPlayer(PlayerFixture::PLAYER_WITH_FAVORITES)->steadyHandsQuarters);
    }

    public function testSuspiciousSolvesAreExcludedFromNewMetrics(): void
    {
        $baseline = $this->query->forPlayer(PlayerFixture::PLAYER_WITH_FAVORITES);

        // A suspicious weekend marathon solve must not move any counter
        $this->insertSolve(
            PlayerFixture::PLAYER_WITH_FAVORITES,
            PuzzleFixture::PUZZLE_2000,
            trackedAt: new DateTimeImmutable('2020-06-06 12:00:00'),
            suspicious: true,
        );

        $snapshot = $this->query->forPlayer(PlayerFixture::PLAYER_WITH_FAVORITES);

        self::assertSame($baseline->weekendSolves, $snapshot->weekendSolves);
        self::assertSame($baseline->marathonerSolves, $snapshot->marathonerSolves);
        self::assertSame($baseline->firstTrySolves, $snapshot->firstTrySolves);
        self::assertSame($baseline->steadyHandsQuarters, $snapshot->steadyHandsQuarters);
    }

    public function testMuscleMemoryCountsTheOwnSoloSolvesOfTheMostSolved500PiecePuzzle(): void
    {
        $player = PlayerFixture::PLAYER_WITH_FAVORITES;
        $puzzle = PuzzleFixture::PUZZLE_500_04;
        $existing = $this->soloSolvesOf($player, $puzzle);

        // Counted: timed and relaxed solo solves, three of them unboxed
        for ($i = 0; $i < 5; $i++) {
            $this->insertSolve($player, $puzzle, trackedAt: new DateTimeImmutable('2020-03-0' . ($i + 1) . ' 12:00:00'));
        }
        $this->insertSolve($player, $puzzle, trackedAt: new DateTimeImmutable('2020-04-01 12:00:00'), seconds: null);
        $this->insertSolve($player, $puzzle, trackedAt: new DateTimeImmutable('2020-04-02 12:00:00'), seconds: null, unboxed: true);
        $this->insertSolve($player, $puzzle, trackedAt: new DateTimeImmutable('2020-04-03 12:00:00'), unboxed: true);
        $this->insertSolve($player, $puzzle, trackedAt: new DateTimeImmutable('2020-04-04 12:00:00'), unboxed: true);
        $this->insertSolve($player, $puzzle, trackedAt: new DateTimeImmutable('2020-04-05 12:00:00'));

        // NOT counted: a suspicious solve, pair results (logged by the player or by a teammate) and another puzzle
        $this->insertSolve($player, $puzzle, trackedAt: new DateTimeImmutable('2020-05-01 12:00:00'), unboxed: true, suspicious: true);
        $this->insertSolve($player, $puzzle, trackedAt: new DateTimeImmutable('2020-05-02 12:00:00'), team: [$player, PlayerFixture::PLAYER_REGULAR], unboxed: true);
        $this->insertSolve(PlayerFixture::PLAYER_REGULAR, $puzzle, trackedAt: new DateTimeImmutable('2020-05-03 12:00:00'), team: [PlayerFixture::PLAYER_REGULAR, $player], unboxed: true);
        $this->insertSolve($player, PuzzleFixture::PUZZLE_1000_01, trackedAt: new DateTimeImmutable('2020-05-04 12:00:00'), unboxed: true);

        $snapshot = $this->query->forPlayer($player);

        self::assertSame($existing['solves'] + 10, $snapshot->muscleMemorySolves);
        self::assertSame($existing['unboxed'] + 3, $snapshot->muscleMemoryUnboxedSolves);

        // The history replay counts the same rows: it still ends at the snapshot, so the tiers can be dated
        self::assertNotNull(self::getContainer()->get(AchievementFirstMetDates::class)->forPlayer($player));
    }

    public function testNightOwlCountsOwnResultsLoggedAtNightOnTheFinishDayInThePlayersTimeZone(): void
    {
        $player = PlayerFixture::PLAYER_WITH_FAVORITES;
        $puzzle = PuzzleFixture::PUZZLE_500_04;

        // Without a time zone nothing counts
        $this->database->executeStatement('UPDATE player SET timezone = NULL WHERE id = :id', ['id' => $player]);
        $this->insertSolve($player, $puzzle, trackedAt: new DateTimeImmutable('2020-06-01 21:30:00'), finishedAt: new DateTimeImmutable('2020-06-01'));
        self::assertSame(0, $this->query->forPlayer($player)->nightOwlSolves);

        $this->database->executeStatement("UPDATE player SET timezone = 'Europe/Prague' WHERE id = :id", ['id' => $player]);
        $baseline = $this->query->forPlayer($player)->nightOwlSolves;

        // Counted (Prague = UTC+2 in June): 03:30 on the finish day; the 23:30 one above counts now too
        $this->insertSolve($player, $puzzle, trackedAt: new DateTimeImmutable('2020-06-03 01:30:00'), finishedAt: new DateTimeImmutable('2020-06-03'));

        // NOT counted: 04:30, 21:30, logged the day after finishing, a pair result a teammate logged at night
        $this->insertSolve($player, $puzzle, trackedAt: new DateTimeImmutable('2020-06-04 02:30:00'), finishedAt: new DateTimeImmutable('2020-06-04'));
        $this->insertSolve($player, $puzzle, trackedAt: new DateTimeImmutable('2020-06-05 19:30:00'), finishedAt: new DateTimeImmutable('2020-06-05'));
        $this->insertSolve($player, $puzzle, trackedAt: new DateTimeImmutable('2020-06-06 21:30:00'), finishedAt: new DateTimeImmutable('2020-06-05'));
        $this->insertSolve(PlayerFixture::PLAYER_REGULAR, $puzzle, trackedAt: new DateTimeImmutable('2020-06-07 21:30:00'), finishedAt: new DateTimeImmutable('2020-06-07'), team: [PlayerFixture::PLAYER_REGULAR, $player]);

        self::assertSame($baseline + 1, $this->query->forPlayer($player)->nightOwlSolves);
        self::assertNotNull(self::getContainer()->get(AchievementFirstMetDates::class)->forPlayer($player));
    }

    public function testPostgresKnowsEveryTimeZoneAPlayerCanHave(): void
    {
        // Night Owl converts with AT TIME ZONE: a zone Postgres does not know would fail the whole snapshot
        /** @var list<string> $unknown */
        $unknown = $this->database->executeQuery(
            'SELECT zone FROM unnest(CAST(:zones AS text[])) AS zone WHERE zone NOT IN (SELECT name FROM pg_timezone_names)',
            ['zones' => '{' . implode(',', TimezoneIdentifier::all()) . '}'],
        )->fetchFirstColumn();

        self::assertSame([], $unknown);
    }

    public function testCompetitorCountsFinishedInPersonCompetitionsTakenPartInOnce(): void
    {
        $player = PlayerFixture::PLAYER_WITH_FAVORITES;
        $baseline = $this->query->forPlayer($player)->competitorEvents;

        // Connected participant of a past event
        $this->insertParticipant(EventsPageFixture::COMPETITION_VALLEY_CUP_LAST_YEAR, $player);
        // Only in a pair result a teammate linked to another past event
        $this->insertSolve(PlayerFixture::PLAYER_REGULAR, PuzzleFixture::PUZZLE_500_04, trackedAt: new DateTimeImmutable('2020-06-01 12:00:00'), team: [PlayerFixture::PLAYER_REGULAR, $player], competitionId: EventsPageFixture::COMPETITION_VALLEY_CUP_TWO_YEARS_AGO);
        // Both connected and with a result: one event
        $this->insertParticipant(OfficialResultsFixture::COMPETITION_RESULTS_CUP, $player);
        $this->insertSolve($player, PuzzleFixture::PUZZLE_500_04, trackedAt: new DateTimeImmutable('2020-06-02 12:00:00'), competitionId: OfficialResultsFixture::COMPETITION_RESULTS_CUP);

        // NOT counted: an event still to come, a removed participant, a suspicious result, an online event
        $this->insertParticipant(CompetitionFixture::COMPETITION_WJPC_2024, $player);
        $this->insertParticipant(EventsPageFixture::COMPETITION_VALLEY_CUP_LAST_YEAR, $player, deleted: true);
        $this->insertSolve($player, PuzzleFixture::PUZZLE_500_04, trackedAt: new DateTimeImmutable('2020-06-03 12:00:00'), suspicious: true, competitionId: EventsPageFixture::COMPETITION_VALLEY_CUP_TWO_YEARS_AGO);
        $this->insertParticipant(OnlineRoundsFixture::COMPETITION_ONLINE_TEST_CUP, $player);

        self::assertSame($baseline + 3, $this->query->forPlayer($player)->competitorEvents);
        self::assertNotNull(self::getContainer()->get(AchievementFirstMetDates::class)->forPlayer($player));

        // A puzzle swap is no competition
        $this->database->executeStatement(
            "UPDATE competition SET event_types = '[\"puzzle_swap\"]' WHERE id = :id",
            ['id' => EventsPageFixture::COMPETITION_VALLEY_CUP_LAST_YEAR],
        );

        self::assertSame($baseline + 2, $this->query->forPlayer($player)->competitorEvents);
    }

    public function testOnlineCompetitorCountsEveryResultAtOnlineCompetitions(): void
    {
        $player = PlayerFixture::PLAYER_WITH_FAVORITES;
        $baseline = $this->query->forPlayer($player)->onlineCompetitorResults;

        // Counted: own results and a pair result a teammate linked, each one
        $this->insertSolve($player, PuzzleFixture::PUZZLE_500_04, trackedAt: new DateTimeImmutable('2020-06-01 12:00:00'), competitionId: EventsPageFixture::COMPETITION_ENDLESS_RELAY);
        $this->insertSolve($player, PuzzleFixture::PUZZLE_500_04, trackedAt: new DateTimeImmutable('2020-06-08 12:00:00'), competitionId: EventsPageFixture::COMPETITION_ENDLESS_RELAY);
        $this->insertSolve(PlayerFixture::PLAYER_REGULAR, PuzzleFixture::PUZZLE_500_04, trackedAt: new DateTimeImmutable('2020-06-15 12:00:00'), team: [PlayerFixture::PLAYER_REGULAR, $player], competitionId: EventsPageFixture::COMPETITION_ENDLESS_RELAY);

        // NOT counted: suspicious, an in-person competition, a draft (the Online Test Cup)
        $this->insertSolve($player, PuzzleFixture::PUZZLE_500_04, trackedAt: new DateTimeImmutable('2020-06-30 12:00:00'), competitionId: OnlineRoundsFixture::COMPETITION_ONLINE_TEST_CUP);
        $this->insertSolve($player, PuzzleFixture::PUZZLE_500_04, trackedAt: new DateTimeImmutable('2020-06-22 12:00:00'), suspicious: true, competitionId: EventsPageFixture::COMPETITION_ENDLESS_RELAY);
        $this->insertSolve($player, PuzzleFixture::PUZZLE_500_04, trackedAt: new DateTimeImmutable('2020-06-29 12:00:00'), competitionId: EventsPageFixture::COMPETITION_VALLEY_CUP_LAST_YEAR);

        self::assertSame($baseline + 3, $this->query->forPlayer($player)->onlineCompetitorResults);
        self::assertNotNull(self::getContainer()->get(AchievementFirstMetDates::class)->forPlayer($player));
    }

    private function insertParticipant(string $competitionId, string $playerId, bool $deleted = false): void
    {
        $this->database->executeStatement(
            "INSERT INTO competition_participant (id, name, country, competition_id, source, player_id, connected_at, deleted_at)
             VALUES (:id, 'Test participant', NULL, :competitionId, 'self_joined', :playerId, '2020-01-01 10:00:00', :deletedAt)",
            [
                'id' => Uuid::uuid7()->toString(),
                'competitionId' => $competitionId,
                'playerId' => $playerId,
                'deletedAt' => $deleted ? '2020-01-02 10:00:00' : null,
            ],
        );
    }

    /**
     * @return array{solves: int, unboxed: int}
     */
    private function soloSolvesOf(string $playerId, string $puzzleId): array
    {
        /** @var array{solves: int|string, unboxed: int|string} $row */
        $row = $this->database->executeQuery(
            "SELECT COUNT(*) AS solves, COUNT(*) FILTER (WHERE unboxed = true) AS unboxed
             FROM puzzle_solving_time
             WHERE player_id = :playerId AND puzzle_id = :puzzleId AND puzzling_type = 'solo' AND suspicious = false",
            ['playerId' => $playerId, 'puzzleId' => $puzzleId],
        )->fetchAssociative();

        return ['solves' => (int) $row['solves'], 'unboxed' => (int) $row['unboxed']];
    }

    /**
     * Mirrors the puzzle_solving_time INSERT used by XpChainRecomputerTest — DAMA rolls the
     * row back after each test.
     *
     * @param list<string>|null $team
     */
    private function insertSolve(
        string $playerId,
        string $puzzleId,
        DateTimeImmutable $trackedAt,
        null|DateTimeImmutable|false $finishedAt = false,
        null|array $team = null,
        bool $suspicious = false,
        null|int $seconds = 3600,
        bool $unboxed = false,
        null|string $competitionId = null,
    ): void {
        $teamJson = null;
        $puzzlingType = 'solo';
        $puzzlersCount = 1;

        if ($team !== null) {
            $puzzlers = [];
            foreach ($team as $teamPlayerId) {
                $puzzlers[] = [
                    'player_id' => $teamPlayerId,
                    'player_name' => null,
                    'player_code' => null,
                    'player_country' => null,
                    'is_private' => false,
                ];
            }
            $teamJson = json_encode(['team_id' => null, 'puzzlers' => $puzzlers], JSON_THROW_ON_ERROR);
            $puzzlersCount = count($team);
            $puzzlingType = $puzzlersCount === 2 ? 'duo' : 'team';
        }

        // false = default to trackedAt, null = explicitly NULL
        $resolvedFinishedAt = $finishedAt === false ? $trackedAt : $finishedAt;

        $this->database->executeStatement(
            'INSERT INTO puzzle_solving_time
                (id, seconds_to_solve, player_id, puzzle_id, tracked_at, verified, team, finished_at,
                 comment, finished_puzzle_photo, first_attempt, unboxed, puzzlers_count, puzzling_type,
                 suspicious, pieces_count_snapshot, competition_id)
             VALUES
                (:id, :seconds, :playerId, :puzzleId, :trackedAt, true, :team, :finishedAt,
                 NULL, NULL, true, :unboxed, :puzzlersCount, :puzzlingType, :suspicious, 500, :competitionId)',
            [
                'id' => Uuid::uuid7()->toString(),
                'playerId' => $playerId,
                'puzzleId' => $puzzleId,
                'trackedAt' => $trackedAt->format('Y-m-d H:i:s'),
                'finishedAt' => $resolvedFinishedAt?->format('Y-m-d H:i:s'),
                'team' => $teamJson,
                'puzzlersCount' => $puzzlersCount,
                'puzzlingType' => $puzzlingType,
                'suspicious' => (int) $suspicious,
                'seconds' => $seconds,
                'unboxed' => (int) $unboxed,
                'competitionId' => $competitionId,
            ],
        );
    }
}
