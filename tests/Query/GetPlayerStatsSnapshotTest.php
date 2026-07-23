<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Query;

use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Psr\Clock\ClockInterface;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Query\GetPlayerStatsSnapshot;
use SpeedPuzzling\Web\Services\ActivityCalendarStreakCalculator;
use SpeedPuzzling\Web\Tests\DataFixtures\PlayerFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\PuzzleFixture;
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

    public function testOwnerScopedCountersForRegularPlayer(): void
    {
        $snapshot = $this->query->forPlayer(PlayerFixture::PLAYER_REGULAR);

        // TIME_46_RELAX_NO_FINISHED_AT is the only owner solve without tracked seconds
        self::assertSame(1, $snapshot->zenPuzzlerSolves);

        // first_attempt=true owner solves: TIME_01, 06, 09, 12, 13, 19, 21, 25, 28, 29, 36, 41
        // (TIME_07/08 are repeats, TIME_46 is not a first attempt)
        // + INTEL_TIME_13, 14, 15 from PuzzleIntelligenceFixture (INTEL_TIME_17 is a repeat)
        self::assertSame(15, $snapshot->firstTrySolves);

        // TIME_45_UNBOXED belongs to PLAYER_WITH_STRIPE, not PLAYER_REGULAR
        self::assertSame(0, $snapshot->unboxedSolves);

        // Owner-solved puzzles come from Ravensburger and Trefl only
        self::assertSame(2, $snapshot->brandExplorerManufacturers);

        // TIME_25 on PUZZLE_2000 (2000 pieces) is the only 2000+ piece owner solve
        self::assertSame(1, $snapshot->marathonerSolves);

        // No fixture solve has a finished puzzle photo
        self::assertSame(0, $snapshot->photographerSolves);

        // 1000pc solo timed solves: TIME_19 (4500s) and TIME_29 (3950s);
        // TIME_12/TIME_41 are duo solves and TIME_46 has no time
        self::assertSame(3950, $snapshot->best1000PieceSoloSeconds);
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
                 suspicious, pieces_count_snapshot)
             VALUES
                (:id, 3600, :playerId, :puzzleId, :trackedAt, true, :team, :finishedAt,
                 NULL, NULL, true, false, :puzzlersCount, :puzzlingType, :suspicious, 500)',
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
            ],
        );
    }
}
