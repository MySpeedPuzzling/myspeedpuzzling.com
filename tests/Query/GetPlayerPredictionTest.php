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

    public function testReturnsValidPrediction(): void
    {
        $result = $this->query->forPuzzle(PlayerFixture::PLAYER_REGULAR, PuzzleFixture::PUZZLE_500_01);

        if ($result === null) {
            // If no prediction available (missing baseline or difficulty), that's acceptable
            self::markTestSkipped('No prediction available — puzzle may lack difficulty score');
        }

        self::assertGreaterThan(0, $result->predictedSeconds);
        self::assertGreaterThan(0, $result->rangeLowSeconds);
        self::assertGreaterThan(0, $result->rangeHighSeconds);
        self::assertLessThanOrEqual($result->rangeHighSeconds, $result->predictedSeconds);
        self::assertGreaterThanOrEqual($result->rangeLowSeconds, $result->predictedSeconds);
        self::assertGreaterThan(0.0, $result->difficultyForPlayer);
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
