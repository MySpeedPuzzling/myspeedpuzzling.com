<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Services\Xp;

use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Services\Xp\XpCalculator;
use SpeedPuzzling\Web\Services\Xp\XpChainRecomputer;
use SpeedPuzzling\Web\Tests\DataFixtures\PlayerFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\PuzzleFixture;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * P8.T3 — the §1.8 anti-abuse guards, verified end-to-end through the live award path.
 * All automatic, all silent: base XP always stays, only bonuses are withheld.
 */
final class XpAntiAbuseTest extends KernelTestCase
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

    public function testPerSolveXpIsCappedAtBase60(): void
    {
        $solveId = $this->insertSolve(PlayerFixture::PLAYER_REGULAR, PuzzleFixture::PUZZLE_9000, 50000, 9000);

        $this->award($solveId);

        self::assertSame(60, $this->amount($solveId, 'solve_base'));
    }

    public function testSpeedBonusRequiresMedianFromThreeDistinctSolvers(): void
    {
        // Two distinct solvers only — even a blazing (plausible) time earns no speed bonus.
        $this->insertSolve(PlayerFixture::PLAYER_PRIVATE, PuzzleFixture::PUZZLE_500_04, 5000, 500);
        $mine = $this->insertSolve(PlayerFixture::PLAYER_REGULAR, PuzzleFixture::PUZZLE_500_04, 2000, 500);

        $this->award($mine);

        self::assertGreaterThan(0, $this->amount($mine, 'solve_base'));
        self::assertSame(0, $this->amount($mine, 'solve_speed_bonus'));
    }

    public function testSpeedBonusGrantedWithReliableMedianAndPlausiblePace(): void
    {
        // Positive control: 3 distinct solvers + fast-but-human time → the bonus exists.
        $this->insertSolve(PlayerFixture::PLAYER_PRIVATE, PuzzleFixture::PUZZLE_500_04, 5000, 500);
        $this->insertSolve(PlayerFixture::PLAYER_ADMIN, PuzzleFixture::PUZZLE_500_04, 6000, 500);
        $mine = $this->insertSolve(PlayerFixture::PLAYER_REGULAR, PuzzleFixture::PUZZLE_500_04, 2000, 500);

        $this->award($mine);

        self::assertGreaterThan(0, $this->amount($mine, 'solve_speed_bonus'));
    }

    public function testImplausiblePpmSilentlyDeniesSpeedBonusButKeepsBase(): void
    {
        // 500 pieces in 600 seconds = 50 PPM — far above the plausibility ceiling
        // (and above any world record), with an otherwise reliable median.
        $this->insertSolve(PlayerFixture::PLAYER_PRIVATE, PuzzleFixture::PUZZLE_500_04, 5000, 500);
        $this->insertSolve(PlayerFixture::PLAYER_ADMIN, PuzzleFixture::PUZZLE_500_04, 6000, 500);
        $mine = $this->insertSolve(PlayerFixture::PLAYER_REGULAR, PuzzleFixture::PUZZLE_500_04, 600, 500);

        $this->award($mine);

        self::assertGreaterThan(0, $this->amount($mine, 'solve_base'), 'Base XP is never withheld — no user-facing accusation');
        self::assertSame(0, $this->amount($mine, 'solve_speed_bonus'));
    }

    public function testRelaxRepeatEarnsExactlyNothing(): void
    {
        $first = $this->insertSolve(PlayerFixture::PLAYER_REGULAR, PuzzleFixture::PUZZLE_500_04, 3600, 500);
        $this->award($first);

        $relaxRepeat = $this->insertSolve(PlayerFixture::PLAYER_REGULAR, PuzzleFixture::PUZZLE_500_04, null, 500);
        $this->award($relaxRepeat);

        $entries = $this->database->fetchOne(
            'SELECT COUNT(*) FROM xp_entry WHERE solving_time_id = :id',
            ['id' => $relaxRepeat],
        );
        self::assertSame(0, (int) (is_numeric($entries) ? $entries : -1));
    }

    private function award(string $solvingTimeId): void
    {
        $this->chainRecomputer->awardForNewSolve($solvingTimeId);
        $this->entityManager->flush();
        $this->entityManager->clear();
    }

    private function amount(string $solvingTimeId, string $reason): int
    {
        $value = $this->database->fetchOne(
            'SELECT COALESCE(SUM(amount), 0) FROM xp_entry WHERE solving_time_id = :id AND reason = :reason',
            ['id' => $solvingTimeId, 'reason' => $reason],
        );

        return is_numeric($value) ? (int) $value : 0;
    }

    private function insertSolve(string $playerId, string $puzzleId, null|int $seconds, int $pieces): string
    {
        $solveId = Uuid::uuid7()->toString();
        $at = XpCalculator::fullFormulaFrom()->modify('+5 days')->format('Y-m-d H:i:s');

        $this->database->executeStatement(
            'INSERT INTO puzzle_solving_time
                (id, seconds_to_solve, player_id, puzzle_id, tracked_at, verified, team, finished_at,
                 comment, finished_puzzle_photo, first_attempt, unboxed, puzzlers_count, puzzling_type,
                 suspicious, pieces_count_snapshot)
             VALUES
                (:id, :seconds, :playerId, :puzzleId, :at, true, NULL, :at,
                 NULL, NULL, false, false, 1, \'solo\', false, :pieces)',
            [
                'id' => $solveId,
                'seconds' => $seconds,
                'playerId' => $playerId,
                'puzzleId' => $puzzleId,
                'at' => $at,
                'pieces' => $pieces,
            ],
        );

        return $solveId;
    }
}
