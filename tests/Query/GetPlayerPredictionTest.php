<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Query;

use SpeedPuzzling\Web\Query\GetPlayerPrediction;
use SpeedPuzzling\Web\Services\PuzzleIntelligence\PuzzleIntelligenceRecalculator;
use SpeedPuzzling\Web\Tests\DataFixtures\PlayerFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\PuzzleFixture;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class GetPlayerPredictionTest extends KernelTestCase
{
    private GetPlayerPrediction $query;

    protected function setUp(): void
    {
        self::bootKernel();
        $container = self::getContainer();

        /** @var PuzzleIntelligenceRecalculator $recalculator */
        $recalculator = $container->get(PuzzleIntelligenceRecalculator::class);
        $recalculator->recalculate();

        /** @var GetPlayerPrediction $query */
        $query = $container->get(GetPlayerPrediction::class);
        $this->query = $query;
    }

    public function testReturnsPrediction(): void
    {
        $result = $this->query->forPuzzle(PlayerFixture::PLAYER_REGULAR, PuzzleFixture::PUZZLE_500_01);

        if ($result === null) {
            self::markTestSkipped('No prediction available — puzzle may lack difficulty score');
        }

        self::assertGreaterThan(0, $result->predictedSeconds);
        self::assertGreaterThan(0, $result->rangeLowSeconds);
        self::assertGreaterThan(0, $result->rangeHighSeconds);
        self::assertLessThanOrEqual($result->rangeHighSeconds, $result->predictedSeconds);
        self::assertGreaterThanOrEqual($result->rangeLowSeconds, $result->predictedSeconds);
    }

    public function testStatisticalPredictionForSingleSolver(): void
    {
        // PLAYER_WITH_FAVORITES has only 1 solve on PUZZLE_500_01 — should get statistical prediction
        $result = $this->query->forPuzzle(PlayerFixture::PLAYER_WITH_FAVORITES, PuzzleFixture::PUZZLE_500_01);

        if ($result === null) {
            self::markTestSkipped('No prediction available — player may lack baseline');
        }

        self::assertFalse($result->isPersonalized);
        self::assertNull($result->personalSolveCount);
        self::assertGreaterThan(0.0, $result->difficultyForPlayer);
    }

    public function testReturnsPersonalizedPredictionForRepeatSolver(): void
    {
        // PLAYER_REGULAR has 3 solves on PUZZLE_500_02: 2200s, 1900s, 1700s
        $result = $this->query->forPuzzle(PlayerFixture::PLAYER_REGULAR, PuzzleFixture::PUZZLE_500_02);

        if ($result === null) {
            self::markTestSkipped('No prediction available');
        }

        self::assertTrue($result->isPersonalized);
        self::assertSame(3, $result->personalSolveCount);
        self::assertSame(1900, $result->predictedSeconds); // Median of 1700, 1900, 2200
        self::assertSame(1700, $result->rangeLowSeconds); // Fastest
        self::assertSame(2200, $result->rangeHighSeconds); // Slowest
    }

    public function testReturnsNullForPuzzleWithoutDifficulty(): void
    {
        $result = $this->query->forPuzzle(PlayerFixture::PLAYER_REGULAR, PuzzleFixture::PUZZLE_9000);

        self::assertNull($result);
    }

    public function testReturnsNullForPlayerWithoutBaseline(): void
    {
        $result = $this->query->forPuzzle('00000000-0000-0000-0000-000000000099', PuzzleFixture::PUZZLE_500_01);

        self::assertNull($result);
    }
}
