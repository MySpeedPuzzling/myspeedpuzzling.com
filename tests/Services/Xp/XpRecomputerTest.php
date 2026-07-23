<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Services\Xp;

use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Services\Xp\XpCalculator;
use SpeedPuzzling\Web\Services\Xp\XpRecomputer;
use SpeedPuzzling\Web\Value\LevelTable;
use SpeedPuzzling\Web\Tests\DataFixtures\PlayerFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\PuzzleFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\PuzzleSolvingTimeFixture;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class XpRecomputerTest extends KernelTestCase
{
    private XpRecomputer $recomputer;
    private Connection $database;
    private EntityManagerInterface $entityManager;

    protected function setUp(): void
    {
        self::bootKernel();
        $container = self::getContainer();

        $this->recomputer = $container->get(XpRecomputer::class);
        $this->database = $container->get(Connection::class);
        $this->entityManager = $container->get(EntityManagerInterface::class);
    }

    public function testRecomputeRebuildsLedgerDeterministically(): void
    {
        $playerId = PlayerFixture::PLAYER_REGULAR;

        // A pre-existing achievement entry must survive the rebuild untouched.
        $badgeId = $this->insertAchievementEntry($playerId, xp: 25);

        // Two solves after the full-formula cutoff exercise weekly boost + daily warm-up.
        $afterCutoff = XpCalculator::fullFormulaFrom()->modify('+10 days');
        $solveA = $this->insertSolve($playerId, PuzzleFixture::PUZZLE_500_04, 3600, $afterCutoff->setTime(9, 0), 500);
        $solveB = $this->insertSolve($playerId, PuzzleFixture::PUZZLE_1500_02, 7200, $afterCutoff->setTime(15, 0), 1500);

        $this->recompute($playerId);

        // Occurrence ladder on PUZZLE_500_02 (fixture solves 20/15/10 days ago): 5 → 3 → 1.
        self::assertSame(5, $this->entryAmount(PuzzleSolvingTimeFixture::TIME_06, 'solve_base'));
        self::assertSame(3, $this->entryAmount(PuzzleSolvingTimeFixture::TIME_07, 'solve_base'));
        self::assertSame(1, $this->entryAmount(PuzzleSolvingTimeFixture::TIME_08, 'solve_base'));

        // Relax solve of a puzzle already solved twice = relax repeat: zero entries.
        self::assertSame([], $this->entriesFor(PuzzleSolvingTimeFixture::TIME_46_RELAX_NO_FINISHED_AT));

        // Backfill solves never receive weekly boost or daily warm-up.
        self::assertSame(
            ['solve_base' => 5],
            $this->entriesFor(PuzzleSolvingTimeFixture::TIME_06),
        );

        // Post-cutoff solve A: base 5, weekly boost 3 (first of week), warm-up 2 (first of day).
        self::assertSame(
            ['solve_base' => 5, 'solve_daily_warmup' => 2, 'solve_weekly_boost' => 3],
            $this->entriesFor($solveA),
        );

        // Post-cutoff solve B same day: base 15, weekly boost 8 — no second warm-up.
        self::assertSame(
            ['solve_base' => 15, 'solve_weekly_boost' => 8],
            $this->entriesFor($solveB),
        );

        // Achievement entry preserved.
        $achievementCount = $this->database->fetchOne(
            'SELECT COUNT(*) FROM xp_entry WHERE badge_id = :badgeId',
            ['badgeId' => $badgeId],
        );
        self::assertSame(1, (int) (is_numeric($achievementCount) ? $achievementCount : 0));

        // Denormalized totals match the ledger and the level curve.
        $this->assertTotalsMatchLedger($playerId);

        // Idempotency: a second run yields an identical ledger and identical totals.
        $firstRun = $this->ledgerSnapshot($playerId);
        $firstTotals = $this->playerTotals($playerId);

        $this->recompute($playerId);

        self::assertSame($firstRun, $this->ledgerSnapshot($playerId));
        self::assertSame($firstTotals, $this->playerTotals($playerId));
        $this->assertTotalsMatchLedger($playerId);
    }

    public function testTeamParticipantEarnsThroughTheirOwnOccurrenceChain(): void
    {
        // TIME_12 is a duo solve of PUZZLE_1000_01 owned by PLAYER_REGULAR with
        // PLAYER_PRIVATE as team member. For the owner it is their first solve of that
        // puzzle (10 × 0.75 = 7.5 → 8); for the participant it comes after their own
        // earlier solo solve (TIME_16), so it is occurrence 2 (10 × 0.75 × 0.5 = 3.75 → 4).
        $this->recompute(PlayerFixture::PLAYER_REGULAR);
        $this->recompute(PlayerFixture::PLAYER_PRIVATE);

        $owner = $this->database->fetchOne(
            'SELECT amount FROM xp_entry WHERE solving_time_id = :id AND player_id = :playerId AND reason = :reason',
            ['id' => PuzzleSolvingTimeFixture::TIME_12, 'playerId' => PlayerFixture::PLAYER_REGULAR, 'reason' => 'solve_base'],
        );
        $participant = $this->database->fetchOne(
            'SELECT amount FROM xp_entry WHERE solving_time_id = :id AND player_id = :playerId AND reason = :reason',
            ['id' => PuzzleSolvingTimeFixture::TIME_12, 'playerId' => PlayerFixture::PLAYER_PRIVATE, 'reason' => 'solve_base'],
        );

        self::assertSame(8, (int) (is_numeric($owner) ? $owner : 0));
        self::assertSame(4, (int) (is_numeric($participant) ? $participant : 0));

        $this->assertTotalsMatchLedger(PlayerFixture::PLAYER_REGULAR);
        $this->assertTotalsMatchLedger(PlayerFixture::PLAYER_PRIVATE);
    }

    public function testSuspiciousSolvesEarnNothing(): void
    {
        $playerId = PlayerFixture::PLAYER_REGULAR;
        $suspicious = $this->insertSolve(
            $playerId,
            PuzzleFixture::PUZZLE_500_04,
            600,
            XpCalculator::fullFormulaFrom()->modify('+20 days'),
            500,
            suspicious: true,
        );

        $this->recompute($playerId);

        self::assertSame([], $this->entriesFor($suspicious));
    }

    private function recompute(string $playerId): void
    {
        $this->recomputer->recomputeForPlayer($playerId);
        $this->entityManager->flush();
        $this->entityManager->clear();
    }

    /**
     * @return array<string, int> reason => amount
     */
    private function entriesFor(string $solvingTimeId): array
    {
        /** @var list<array{reason: string, amount: int}> $rows */
        $rows = $this->database->fetchAllAssociative(
            'SELECT reason, amount FROM xp_entry WHERE solving_time_id = :id ORDER BY reason',
            ['id' => $solvingTimeId],
        );

        $entries = [];
        foreach ($rows as $row) {
            $entries[$row['reason']] = $row['amount'];
        }

        return $entries;
    }

    private function entryAmount(string $solvingTimeId, string $reason): int
    {
        $value = $this->database->fetchOne(
            'SELECT amount FROM xp_entry WHERE solving_time_id = :id AND reason = :reason',
            ['id' => $solvingTimeId, 'reason' => $reason],
        );

        return is_numeric($value) ? (int) $value : 0;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function ledgerSnapshot(string $playerId): array
    {
        return $this->database->fetchAllAssociative(
            'SELECT reason, amount, solving_time_id, badge_id, in_weekly_delta, earned_at
             FROM xp_entry WHERE player_id = :playerId
             ORDER BY earned_at, solving_time_id, reason',
            ['playerId' => $playerId],
        );
    }

    /**
     * @return array{xp_total: int, level: int}
     */
    private function playerTotals(string $playerId): array
    {
        /** @var array{xp_total: int, level: int}|false $row */
        $row = $this->database->fetchAssociative(
            'SELECT xp_total, level FROM player WHERE id = :playerId',
            ['playerId' => $playerId],
        );

        self::assertNotFalse($row);

        return $row;
    }

    private function assertTotalsMatchLedger(string $playerId): void
    {
        $ledgerSum = $this->database->fetchOne(
            'SELECT COALESCE(SUM(amount), 0) FROM xp_entry WHERE player_id = :playerId',
            ['playerId' => $playerId],
        );
        $totals = $this->playerTotals($playerId);

        self::assertSame((int) (is_numeric($ledgerSum) ? $ledgerSum : -1), $totals['xp_total']);
        self::assertSame(LevelTable::levelForXp($totals['xp_total']), $totals['level']);
    }

    private function insertAchievementEntry(string $playerId, int $xp): string
    {
        $badgeId = Uuid::uuid7()->toString();

        $this->database->executeStatement(
            "INSERT INTO badge (id, player_id, type, earned_at, tier)
             VALUES (:id, :playerId, 'puzzles_solved', NOW(), 1)",
            ['id' => $badgeId, 'playerId' => $playerId],
        );

        $this->database->executeStatement(
            "INSERT INTO xp_entry (id, player_id, amount, reason, in_weekly_delta, earned_at, created_at, badge_id)
             VALUES (:id, :playerId, :amount, 'achievement', true, NOW(), NOW(), :badgeId)",
            ['id' => Uuid::uuid7()->toString(), 'playerId' => $playerId, 'amount' => $xp, 'badgeId' => $badgeId],
        );

        return $badgeId;
    }

    private function insertSolve(
        string $playerId,
        string $puzzleId,
        int $seconds,
        \DateTimeImmutable $at,
        int $piecesSnapshot,
        bool $suspicious = false,
    ): string {
        $solveId = Uuid::uuid7()->toString();

        $this->database->executeStatement(
            'INSERT INTO puzzle_solving_time
                (id, seconds_to_solve, player_id, puzzle_id, tracked_at, verified, team, finished_at,
                 comment, finished_puzzle_photo, first_attempt, unboxed, puzzlers_count, puzzling_type,
                 suspicious, pieces_count_snapshot)
             VALUES
                (:id, :seconds, :playerId, :puzzleId, :at, true, NULL, :at,
                 NULL, NULL, false, false, 1, :puzzlingType, :suspicious, :pieces)',
            [
                'id' => $solveId,
                'seconds' => $seconds,
                'playerId' => $playerId,
                'puzzleId' => $puzzleId,
                'at' => $at->format('Y-m-d H:i:s'),
                'puzzlingType' => 'solo',
                'suspicious' => $suspicious ? 'true' : 'false',
                'pieces' => $piecesSnapshot,
            ],
        );

        return $solveId;
    }
}
