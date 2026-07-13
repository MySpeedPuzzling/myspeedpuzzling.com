<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Services\Xp;

use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Services\Badges\BadgeEvaluator;
use SpeedPuzzling\Web\Services\Xp\XpCalculator;
use SpeedPuzzling\Web\Services\Xp\XpChainRecomputer;
use SpeedPuzzling\Web\Tests\DataFixtures\PlayerFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\PuzzleFixture;
use SpeedPuzzling\Web\Value\LevelTable;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class XpChainRecomputerTest extends KernelTestCase
{
    private XpChainRecomputer $chainRecomputer;
    private Connection $database;
    private EntityManagerInterface $entityManager;

    protected function setUp(): void
    {
        self::bootKernel();
        $container = self::getContainer();

        $this->chainRecomputer = $container->get(XpChainRecomputer::class);
        $this->database = $container->get(Connection::class);
        $this->entityManager = $container->get(EntityManagerInterface::class);
    }

    public function testAwardCreatesFullReceiptForNewSolve(): void
    {
        $at = XpCalculator::fullFormulaFrom()->modify('+10 days');
        $solveId = $this->insertSolve(PlayerFixture::PLAYER_REGULAR, PuzzleFixture::PUZZLE_500_04, 3600, $at, 500);

        $this->award($solveId);

        self::assertSame(
            ['solve_base' => 5, 'solve_daily_warmup' => 2, 'solve_weekly_boost' => 3],
            $this->entriesFor($solveId, PlayerFixture::PLAYER_REGULAR),
        );
        $this->assertTotalsMatchLedger(PlayerFixture::PLAYER_REGULAR);
    }

    public function testAwardIsIdempotentUnderRedelivery(): void
    {
        $at = XpCalculator::fullFormulaFrom()->modify('+10 days');
        $solveId = $this->insertSolve(PlayerFixture::PLAYER_REGULAR, PuzzleFixture::PUZZLE_500_04, 3600, $at, 500);

        $this->award($solveId);
        $firstTotals = $this->playerTotals(PlayerFixture::PLAYER_REGULAR);

        $this->award($solveId);

        self::assertSame($firstTotals, $this->playerTotals(PlayerFixture::PLAYER_REGULAR));
        self::assertSame(3, $this->entryCountFor($solveId));
    }

    public function testAwardTeamSolveGrantsEveryRegisteredParticipant(): void
    {
        $at = XpCalculator::fullFormulaFrom()->modify('+10 days');
        $solveId = $this->insertSolve(
            PlayerFixture::PLAYER_REGULAR,
            PuzzleFixture::PUZZLE_500_04,
            3600,
            $at,
            500,
            team: [PlayerFixture::PLAYER_REGULAR, PlayerFixture::PLAYER_PRIVATE],
        );

        $this->award($solveId);

        // 500pc duo: base 5 × 0.75 = 3.75 → 4 for BOTH participants, own weekly/daily each.
        $owner = $this->entriesFor($solveId, PlayerFixture::PLAYER_REGULAR);
        $teammate = $this->entriesFor($solveId, PlayerFixture::PLAYER_PRIVATE);

        self::assertSame(['solve_base' => 4, 'solve_daily_warmup' => 2, 'solve_weekly_boost' => 2], $owner);
        self::assertSame($owner, $teammate);
        $this->assertTotalsMatchLedger(PlayerFixture::PLAYER_REGULAR);
        $this->assertTotalsMatchLedger(PlayerFixture::PLAYER_PRIVATE);
    }

    public function testWeeklyBoostStopsAfterFiveSolvesAndResetsNextWeek(): void
    {
        $base = XpCalculator::fullFormulaFrom()->modify('+28 days');
        $monday = $base->setISODate((int) $base->format('o'), (int) $base->format('W'))->setTime(12, 0);

        $puzzles = [
            PuzzleFixture::PUZZLE_500_04,
            PuzzleFixture::PUZZLE_1000_04,
            PuzzleFixture::PUZZLE_1000_05,
            PuzzleFixture::PUZZLE_3000,
            PuzzleFixture::PUZZLE_4000,
            PuzzleFixture::PUZZLE_5000,
        ];

        $solveIds = [];
        foreach ($puzzles as $day => $puzzleId) {
            $solveId = $this->insertSolve(PlayerFixture::PLAYER_REGULAR, $puzzleId, 7200, $monday->modify("+{$day} days"), 500);
            $this->award($solveId);
            $solveIds[] = $solveId;
        }

        // First five solves of the ISO week are boosted, the sixth is not.
        for ($i = 0; $i < 5; $i++) {
            self::assertArrayHasKey('solve_weekly_boost', $this->entriesFor($solveIds[$i], PlayerFixture::PLAYER_REGULAR), "Solve {$i} must be boosted");
        }
        self::assertArrayNotHasKey('solve_weekly_boost', $this->entriesFor($solveIds[5], PlayerFixture::PLAYER_REGULAR));

        // Every solve was on its own day — each gets the daily warm-up.
        foreach ($solveIds as $solveId) {
            self::assertArrayHasKey('solve_daily_warmup', $this->entriesFor($solveId, PlayerFixture::PLAYER_REGULAR));
        }

        // A solve the following Monday starts a fresh week and is boosted again.
        $nextWeek = $this->insertSolve(PlayerFixture::PLAYER_REGULAR, PuzzleFixture::PUZZLE_6000, 7200, $monday->modify('+7 days'), 6000);
        $this->award($nextWeek);

        self::assertArrayHasKey('solve_weekly_boost', $this->entriesFor($nextWeek, PlayerFixture::PLAYER_REGULAR));
    }

    public function testEditRebuildsOccurrenceChainInBothDirections(): void
    {
        $at = XpCalculator::fullFormulaFrom()->modify('+10 days');
        $solveA = $this->insertSolve(PlayerFixture::PLAYER_REGULAR, PuzzleFixture::PUZZLE_500_04, 3600, $at->setTime(9, 0), 500);
        $solveB = $this->insertSolve(PlayerFixture::PLAYER_REGULAR, PuzzleFixture::PUZZLE_500_04, 3500, $at->modify('+1 day')->setTime(9, 0), 500);
        $this->award($solveA);
        $this->award($solveB);

        self::assertSame(5, $this->entriesFor($solveA, PlayerFixture::PLAYER_REGULAR)['solve_base']);
        self::assertSame(3, $this->entriesFor($solveB, PlayerFixture::PLAYER_REGULAR)['solve_base']);

        // "Edit" solve B to a finished date BEFORE solve A → canonical order flips.
        $this->database->executeStatement(
            'UPDATE puzzle_solving_time SET finished_at = :at WHERE id = :id',
            ['at' => $at->modify('-1 day')->setTime(9, 0)->format('Y-m-d H:i:s'), 'id' => $solveB],
        );

        $this->chainRecomputer->rebuildChainForEditedSolve($solveB);
        $this->entityManager->flush();
        $this->entityManager->clear();

        self::assertSame(3, $this->entriesFor($solveA, PlayerFixture::PLAYER_REGULAR)['solve_base']);
        self::assertSame(5, $this->entriesFor($solveB, PlayerFixture::PLAYER_REGULAR)['solve_base']);
        $this->assertTotalsMatchLedger(PlayerFixture::PLAYER_REGULAR);
    }

    public function testDeleteCompensatesAndPromotesRemainingChain(): void
    {
        $at = XpCalculator::fullFormulaFrom()->modify('+10 days');
        $solveA = $this->insertSolve(PlayerFixture::PLAYER_REGULAR, PuzzleFixture::PUZZLE_500_04, 3600, $at->setTime(9, 0), 500);
        $solveB = $this->insertSolve(PlayerFixture::PLAYER_REGULAR, PuzzleFixture::PUZZLE_500_04, 3500, $at->modify('+1 day')->setTime(9, 0), 500);
        $this->award($solveA);
        $this->award($solveB);

        // Simulate DeletePuzzleSolvingTimeHandler: row removed, then the async message.
        $this->database->executeStatement('DELETE FROM puzzle_solving_time WHERE id = :id', ['id' => $solveA]);

        $this->chainRecomputer->compensateAndRebuildAfterDeletion($solveA, PuzzleFixture::PUZZLE_500_04);
        $this->entityManager->flush();
        $this->entityManager->clear();

        // Solve A: original receipt (5+3+2) preserved plus exact negative mirrors.
        $aEntries = $this->allEntriesFor($solveA, PlayerFixture::PLAYER_REGULAR);
        self::assertSame(0, array_sum(array_column($aEntries, 'amount')));
        self::assertCount(6, $aEntries);
        self::assertSame(3, $this->countByReason($aEntries, 'solve_compensation'));

        // Solve B got promoted to first occurrence and now owns the week/day bonuses.
        self::assertSame(
            ['solve_base' => 5, 'solve_daily_warmup' => 2, 'solve_weekly_boost' => 3],
            $this->entriesFor($solveB, PlayerFixture::PLAYER_REGULAR),
        );

        $this->assertTotalsMatchLedger(PlayerFixture::PLAYER_REGULAR);
        self::assertSame(10, $this->playerTotals(PlayerFixture::PLAYER_REGULAR)['xp_total']);
    }

    public function testDeleteIsIdempotentUnderRedelivery(): void
    {
        $at = XpCalculator::fullFormulaFrom()->modify('+10 days');
        $solveA = $this->insertSolve(PlayerFixture::PLAYER_REGULAR, PuzzleFixture::PUZZLE_500_04, 3600, $at, 500);
        $this->award($solveA);

        $this->database->executeStatement('DELETE FROM puzzle_solving_time WHERE id = :id', ['id' => $solveA]);

        $this->chainRecomputer->compensateAndRebuildAfterDeletion($solveA, PuzzleFixture::PUZZLE_500_04);
        $this->entityManager->flush();
        $this->entityManager->clear();
        $firstTotals = $this->playerTotals(PlayerFixture::PLAYER_REGULAR);
        $firstCount = $this->entryCountFor($solveA);

        $this->chainRecomputer->compensateAndRebuildAfterDeletion($solveA, PuzzleFixture::PUZZLE_500_04);
        $this->entityManager->flush();
        $this->entityManager->clear();

        self::assertSame($firstTotals, $this->playerTotals(PlayerFixture::PLAYER_REGULAR));
        self::assertSame($firstCount, $this->entryCountFor($solveA));
    }

    public function testDifficultySettlementLandsOnceWhenPuzzleGetsRated(): void
    {
        $at = XpCalculator::fullFormulaFrom()->modify('+10 days');
        $solveId = $this->insertSolve(PlayerFixture::PLAYER_REGULAR, PuzzleFixture::PUZZLE_500_04, 3600, $at, 500);
        $this->award($solveId);

        self::assertArrayNotHasKey('solve_difficulty_bonus', $this->entriesFor($solveId, PlayerFixture::PLAYER_REGULAR));

        $this->ratePuzzle(PuzzleFixture::PUZZLE_500_04, tier: 5);

        $settled = $this->settle();
        self::assertGreaterThanOrEqual(1, $settled);

        $entries = $this->entriesFor($solveId, PlayerFixture::PLAYER_REGULAR);
        // base_part 5.0 × (1.40 − 1.00) = 2 — settled with the tier at settlement time.
        self::assertSame(2, $entries['difficulty_settlement']);

        $weeklyDelta = $this->database->fetchOne(
            "SELECT in_weekly_delta FROM xp_entry WHERE solving_time_id = :id AND reason = 'difficulty_settlement'",
            ['id' => $solveId],
        );
        self::assertFalse((bool) $weeklyDelta, 'Settlements never count toward the weekly delta.');

        // Frozen forever: the second run settles nothing more for this solve.
        $this->settle();
        self::assertSame(1, $this->countEntries($solveId, 'difficulty_settlement'));

        $this->assertTotalsMatchLedger(PlayerFixture::PLAYER_REGULAR);
    }

    public function testSpeedSettlementLandsWhenMedianBecomesReliable(): void
    {
        $at = XpCalculator::fullFormulaFrom()->modify('+10 days');
        $solveId = $this->insertSolve(PlayerFixture::PLAYER_REGULAR, PuzzleFixture::PUZZLE_500_04, 1000, $at, 500);
        $this->award($solveId);

        // Only one distinct solver — no speed bonus at award time.
        self::assertArrayNotHasKey('solve_speed_bonus', $this->entriesFor($solveId, PlayerFixture::PLAYER_REGULAR));

        // Two more solvers appear → median from 3 distinct solvers exists now.
        $this->insertSolve(PlayerFixture::PLAYER_PRIVATE, PuzzleFixture::PUZZLE_500_04, 5000, $at->modify('+1 day'), 500);
        $this->insertSolve(PlayerFixture::PLAYER_ADMIN, PuzzleFixture::PUZZLE_500_04, 6000, $at->modify('+2 days'), 500);

        $this->settle();

        $entries = $this->entriesFor($solveId, PlayerFixture::PLAYER_REGULAR);
        // Fastest of [1000, 5000, 6000] → top-10%: core 5.0 × 0.15 = 0.75 → 1.
        self::assertSame(1, $entries['speed_settlement']);

        // Frozen forever.
        $this->settle();
        self::assertSame(1, $this->countEntries($solveId, 'speed_settlement'));
    }

    public function testBadgeEvaluatorGrantsAchievementXpOncePerTier(): void
    {
        $container = self::getContainer();
        $evaluator = $container->get(BadgeEvaluator::class);

        $newBadges = $evaluator->recalculateForPlayer(PlayerFixture::PLAYER_REGULAR);
        $this->entityManager->flush();

        self::assertNotEmpty($newBadges);

        // P3 expansion proof: the new conditions evaluate and award — the fixture player
        // has 1 relax solve (Zen Puzzler Bronze) and 15 first-attempt solves (First Try Bronze).
        $earnedTypes = array_map(static fn ($badge) => $badge->type->value, $newBadges);
        self::assertContains('zen_puzzler', $earnedTypes);
        self::assertContains('first_try', $earnedTypes);

        foreach ($newBadges as $badge) {
            $amount = $this->database->fetchOne(
                "SELECT amount FROM xp_entry WHERE badge_id = :badgeId AND reason = 'achievement'",
                ['badgeId' => $badge->id->toString()],
            );
            $expected = match ($badge->tier) {
                1 => 5,
                2 => 10,
                3 => 25,
                4 => 50,
                5 => 100,
                null => 25,
                default => self::fail('Unexpected tier'),
            };
            self::assertSame($expected, is_numeric($amount) ? (int) $amount : null, "Badge {$badge->type->value} tier {$badge->tier}");
        }

        $this->assertTotalsMatchLedger(PlayerFixture::PLAYER_REGULAR);

        // Re-evaluation grants nothing new.
        $countBefore = $this->achievementEntryCount(PlayerFixture::PLAYER_REGULAR);
        $evaluator->recalculateForPlayer(PlayerFixture::PLAYER_REGULAR);
        $this->entityManager->flush();
        self::assertSame($countBefore, $this->achievementEntryCount(PlayerFixture::PLAYER_REGULAR));
    }

    public function testBackfilledAchievementXpStaysOutOfWeeklyDelta(): void
    {
        $evaluator = self::getContainer()->get(BadgeEvaluator::class);

        $newBadges = $evaluator->recalculateForPlayer(PlayerFixture::PLAYER_PRIVATE, isBackfill: true);
        $this->entityManager->flush();

        self::assertNotEmpty($newBadges);

        $inDelta = $this->database->fetchOne(
            "SELECT COUNT(*) FROM xp_entry WHERE player_id = :playerId AND reason = 'achievement' AND in_weekly_delta = true",
            ['playerId' => PlayerFixture::PLAYER_PRIVATE],
        );

        self::assertSame(0, is_numeric($inDelta) ? (int) $inDelta : -1);
    }

    private function award(string $solvingTimeId): void
    {
        $this->chainRecomputer->awardForNewSolve($solvingTimeId);
        $this->entityManager->flush();
        $this->entityManager->clear();
    }

    private function settle(): int
    {
        $settled = $this->chainRecomputer->settlePendingBonuses();
        $this->entityManager->flush();
        $this->entityManager->clear();

        return $settled;
    }

    /**
     * @return array<string, int> reason => amount
     */
    private function entriesFor(string $solvingTimeId, string $playerId): array
    {
        /** @var list<array{reason: string, amount: int}> $rows */
        $rows = $this->database->fetchAllAssociative(
            'SELECT reason, amount FROM xp_entry WHERE solving_time_id = :id AND player_id = :playerId ORDER BY reason',
            ['id' => $solvingTimeId, 'playerId' => $playerId],
        );

        $entries = [];
        foreach ($rows as $row) {
            $entries[$row['reason']] = $row['amount'];
        }

        return $entries;
    }

    /**
     * @return list<array{reason: string, amount: int}>
     */
    private function allEntriesFor(string $solvingTimeId, string $playerId): array
    {
        /** @var list<array{reason: string, amount: int}> $rows */
        $rows = $this->database->fetchAllAssociative(
            'SELECT reason, amount FROM xp_entry WHERE solving_time_id = :id AND player_id = :playerId ORDER BY created_at, reason',
            ['id' => $solvingTimeId, 'playerId' => $playerId],
        );

        return $rows;
    }

    /**
     * @param list<array{reason: string, amount: int}> $entries
     */
    private function countByReason(array $entries, string $reason): int
    {
        return count(array_filter($entries, static fn (array $entry): bool => $entry['reason'] === $reason));
    }

    private function entryCountFor(string $solvingTimeId): int
    {
        $value = $this->database->fetchOne(
            'SELECT COUNT(*) FROM xp_entry WHERE solving_time_id = :id',
            ['id' => $solvingTimeId],
        );

        return is_numeric($value) ? (int) $value : -1;
    }

    private function countEntries(string $solvingTimeId, string $reason): int
    {
        $value = $this->database->fetchOne(
            'SELECT COUNT(*) FROM xp_entry WHERE solving_time_id = :id AND reason = :reason',
            ['id' => $solvingTimeId, 'reason' => $reason],
        );

        return is_numeric($value) ? (int) $value : -1;
    }

    private function achievementEntryCount(string $playerId): int
    {
        $value = $this->database->fetchOne(
            "SELECT COUNT(*) FROM xp_entry WHERE player_id = :playerId AND reason = 'achievement'",
            ['playerId' => $playerId],
        );

        return is_numeric($value) ? (int) $value : -1;
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

    private function ratePuzzle(string $puzzleId, int $tier): void
    {
        $this->database->executeStatement(
            "INSERT INTO puzzle_difficulty (puzzle_id, difficulty_tier, difficulty_score, confidence, sample_size, computed_at)
             VALUES (:puzzleId, :tier, 50, 'high', 10, NOW())",
            ['puzzleId' => $puzzleId, 'tier' => $tier],
        );
    }

    /**
     * @param list<string>|null $team
     */
    private function insertSolve(
        string $playerId,
        string $puzzleId,
        null|int $seconds,
        \DateTimeImmutable $at,
        int $piecesSnapshot,
        null|array $team = null,
    ): string {
        $solveId = Uuid::uuid7()->toString();

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

        $this->database->executeStatement(
            'INSERT INTO puzzle_solving_time
                (id, seconds_to_solve, player_id, puzzle_id, tracked_at, verified, team, finished_at,
                 comment, finished_puzzle_photo, first_attempt, unboxed, puzzlers_count, puzzling_type,
                 suspicious, pieces_count_snapshot)
             VALUES
                (:id, :seconds, :playerId, :puzzleId, :at, true, :team, :at,
                 NULL, NULL, false, false, :puzzlersCount, :puzzlingType, false, :pieces)',
            [
                'id' => $solveId,
                'seconds' => $seconds,
                'playerId' => $playerId,
                'puzzleId' => $puzzleId,
                'at' => $at->format('Y-m-d H:i:s'),
                'team' => $teamJson,
                'puzzlersCount' => $puzzlersCount,
                'puzzlingType' => $puzzlingType,
                'pieces' => $piecesSnapshot,
            ],
        );

        return $solveId;
    }
}
