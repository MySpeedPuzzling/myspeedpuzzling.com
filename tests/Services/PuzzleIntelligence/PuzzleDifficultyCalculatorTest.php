<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Services\PuzzleIntelligence;

use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Entity\PlayerBaseline;
use SpeedPuzzling\Web\Entity\Player;
use SpeedPuzzling\Web\Services\PuzzleIntelligence\PlayerBaselineCalculator;
use SpeedPuzzling\Web\Services\PuzzleIntelligence\PuzzleDifficultyCalculator;
use SpeedPuzzling\Web\Tests\DataFixtures\PlayerFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\PuzzleFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\PuzzleIntelligenceFixture;
use SpeedPuzzling\Web\Value\MetricConfidence;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class PuzzleDifficultyCalculatorTest extends KernelTestCase
{
    private PuzzleDifficultyCalculator $calculator;
    private PlayerBaselineCalculator $baselineCalculator;

    protected function setUp(): void
    {
        self::bootKernel();
        $container = self::getContainer();
        /** @var PuzzleDifficultyCalculator $calculator */
        $calculator = $container->get(PuzzleDifficultyCalculator::class);
        $this->calculator = $calculator;
        /** @var PlayerBaselineCalculator $baselineCalculator */
        $baselineCalculator = $container->get(PlayerBaselineCalculator::class);
        $this->baselineCalculator = $baselineCalculator;

        // First, compute and persist baselines for all players (required for difficulty calculation)
        $this->persistBaselines();
    }

    public function testCalculatesDifficultyForPuzzleWithEnoughSolvers(): void
    {
        // PUZZLE_500_01 has all 5 players solving it (first attempt)
        $result = $this->calculator->calculateForPuzzle(PuzzleFixture::PUZZLE_500_01);

        self::assertNotNull($result['difficulty_score'], 'PUZZLE_500_01 should have a difficulty score');
        self::assertNotNull($result['difficulty_tier']);
        self::assertGreaterThanOrEqual(5, $result['sample_size']);
        self::assertNotSame(MetricConfidence::Insufficient, $result['confidence']);

        // Difficulty score should be around 1.0 (average)
        self::assertGreaterThan(0.5, $result['difficulty_score']);
        self::assertLessThan(2.0, $result['difficulty_score']);
    }

    public function testReturnsInsufficientForPuzzleWithNoSolvers(): void
    {
        // PUZZLE_9000 has no solving times
        $result = $this->calculator->calculateForPuzzle(PuzzleFixture::PUZZLE_9000);

        self::assertNull($result['difficulty_score']);
        self::assertNull($result['difficulty_tier']);
        self::assertSame(MetricConfidence::Insufficient, $result['confidence']);
        self::assertSame(0, $result['sample_size']);
    }

    public function testDifficultyTierAssignment(): void
    {
        $result = $this->calculator->calculateForPuzzle(PuzzleFixture::PUZZLE_500_01);

        self::assertNotNull($result['difficulty_score']);
        self::assertNotNull($result['difficulty_tier']);

        // Verify tier matches score boundaries
        $score = $result['difficulty_score'];
        $tier = $result['difficulty_tier'];

        if ($score < 0.70) {
            self::assertSame(\SpeedPuzzling\Web\Value\DifficultyTier::VeryEasy, $tier);
        } elseif ($score < 0.85) {
            self::assertSame(\SpeedPuzzling\Web\Value\DifficultyTier::Easy, $tier);
        } elseif ($score < 0.95) {
            self::assertSame(\SpeedPuzzling\Web\Value\DifficultyTier::Moderate, $tier);
        } elseif ($score < 1.05) {
            self::assertSame(\SpeedPuzzling\Web\Value\DifficultyTier::Average, $tier);
        } elseif ($score < 1.20) {
            self::assertSame(\SpeedPuzzling\Web\Value\DifficultyTier::Challenging, $tier);
        } elseif ($score < 1.45) {
            self::assertSame(\SpeedPuzzling\Web\Value\DifficultyTier::Hard, $tier);
        } else {
            self::assertSame(\SpeedPuzzling\Web\Value\DifficultyTier::Extreme, $tier);
        }
    }

    public function testMultiplePuzzlesHaveDifferentDifficulties(): void
    {
        // Calculate difficulty for multiple puzzles
        $result01 = $this->calculator->calculateForPuzzle(PuzzleFixture::PUZZLE_500_01);
        $resultA = $this->calculator->calculateForPuzzle(PuzzleIntelligenceFixture::INTEL_PUZZLE_A);

        // Both should have scores (enough players solve them in fixtures)
        self::assertNotNull($result01['difficulty_score']);
        self::assertNotNull($resultA['difficulty_score']);
    }

    private function persistBaselines(): void
    {
        /** @var ClockInterface $clock */
        $clock = self::getContainer()->get(ClockInterface::class);
        /** @var EntityManagerInterface $em */
        $em = self::getContainer()->get(EntityManagerInterface::class);

        $playerIds = [
            PlayerFixture::PLAYER_REGULAR,
            PlayerFixture::PLAYER_PRIVATE,
            PlayerFixture::PLAYER_ADMIN,
            PlayerFixture::PLAYER_WITH_FAVORITES,
            PlayerFixture::PLAYER_WITH_STRIPE,
        ];

        foreach ($playerIds as $playerId) {
            $result = $this->baselineCalculator->calculateForPlayer($playerId, 500);

            if ($result === null) {
                continue;
            }

            $player = $em->find(Player::class, Uuid::fromString($playerId));
            assert($player instanceof Player);

            $baseline = new PlayerBaseline(
                id: Uuid::uuid7(),
                player: $player,
                piecesCount: 500,
                baselineSeconds: $result['baseline_seconds'],
                qualifyingSolvesCount: $result['qualifying_count'],
                computedAt: $clock->now(),
            );

            $em->persist($baseline);
        }

        $em->flush();
    }
}
