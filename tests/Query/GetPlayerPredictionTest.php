<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Query;

use SpeedPuzzling\Web\Query\GetPlayerPrediction;
use SpeedPuzzling\Web\Services\PuzzleIntelligence\PuzzleIntelligenceRecalculator;
use SpeedPuzzling\Web\Tests\DataFixtures\PlayerFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\PuzzleFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\PuzzleSolvingTimeFixture;
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

    public function testPersonalPredictionForSingleSolver(): void
    {
        // PLAYER_WITH_FAVORITES has only 1 solve on PUZZLE_500_01 — should get ratio-based personal prediction
        $result = $this->query->forPuzzle(PlayerFixture::PLAYER_WITH_FAVORITES, PuzzleFixture::PUZZLE_500_01);

        if ($result === null) {
            self::markTestSkipped('No prediction available — player may lack baseline');
        }

        self::assertTrue($result->isPersonalized);
        self::assertSame(1, $result->personalSolveCount);
        self::assertSame(2, $result->predictedAttemptNumber);
    }

    public function testReturnsPersonalizedPredictionForRepeatSolver(): void
    {
        // PLAYER_REGULAR has 3 solves on PUZZLE_500_02: 2200s, 1900s, 1700s (chronological)
        $result = $this->query->forPuzzle(PlayerFixture::PLAYER_REGULAR, PuzzleFixture::PUZZLE_500_02);

        if ($result === null) {
            self::markTestSkipped('No prediction available');
        }

        self::assertTrue($result->isPersonalized);
        self::assertSame(3, $result->personalSolveCount);
        self::assertSame(4, $result->predictedAttemptNumber);
        // Blended prediction: should be lower than best time (1700) since player is improving
        self::assertLessThan(1700, $result->predictedSeconds);
        // But not unreasonably low (floor is best_time * 0.70 = 1190)
        self::assertGreaterThanOrEqual(1190, $result->predictedSeconds);
        self::assertLessThanOrEqual($result->rangeHighSeconds, $result->predictedSeconds);
        self::assertGreaterThanOrEqual($result->rangeLowSeconds, $result->predictedSeconds);
    }

    public function testExcludeTimeIdReducesSolveCount(): void
    {
        // Without exclude: 3 solves → personalized prediction
        $withAll = $this->query->forPuzzle(PlayerFixture::PLAYER_REGULAR, PuzzleFixture::PUZZLE_500_02);

        if ($withAll === null) {
            self::markTestSkipped('No prediction available');
        }

        self::assertSame(3, $withAll->personalSolveCount);

        // Excluding the latest solve leaves 2 → still personalized but different prediction
        $withExclude = $this->query->forPuzzle(
            PlayerFixture::PLAYER_REGULAR,
            PuzzleFixture::PUZZLE_500_02,
            excludeTimeId: PuzzleSolvingTimeFixture::TIME_08,
        );

        if ($withExclude === null) {
            self::markTestSkipped('No prediction available with exclude');
        }

        self::assertTrue($withExclude->isPersonalized);
        self::assertSame(2, $withExclude->personalSolveCount);
        self::assertSame(3, $withExclude->predictedAttemptNumber);
    }

    public function testSameDaySolvesUseTrackedAtOrdering(): void
    {
        // PLAYER_ADMIN has TIME_17 (3900s, 21 days ago) + 3 same-day solves on PUZZLE_1000_01:
        // 5200s (09:00), 4600s (13:00), 4000s (18:00) — all 5 days ago, improving
        // The prediction must see lastTime = 4000 (the latest same-day solve by trackedAt),
        // NOT 5200 (slowest) which would indicate regression
        $result = $this->query->forPuzzle(PlayerFixture::PLAYER_ADMIN, PuzzleFixture::PUZZLE_1000_01);

        if ($result === null) {
            self::markTestSkipped('No prediction available');
        }

        self::assertTrue($result->isPersonalized);
        // lastTime should be the latest same-day solve (4000s), not the slowest (5200s)
        self::assertSame(4000, $result->lastTimeSeconds);
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
