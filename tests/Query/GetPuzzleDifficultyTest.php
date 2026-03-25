<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Query;

use SpeedPuzzling\Web\Query\GetPuzzleDifficulty;
use SpeedPuzzling\Web\Services\PuzzleIntelligence\PuzzleIntelligenceRecalculator;
use SpeedPuzzling\Web\Tests\DataFixtures\PuzzleFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\PuzzleIntelligenceFixture;
use SpeedPuzzling\Web\Value\MetricConfidence;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class GetPuzzleDifficultyTest extends KernelTestCase
{
    private GetPuzzleDifficulty $query;

    protected function setUp(): void
    {
        self::bootKernel();
        $container = self::getContainer();

        // Populate intelligence data
        /** @var PuzzleIntelligenceRecalculator $recalculator */
        $recalculator = $container->get(PuzzleIntelligenceRecalculator::class);
        $recalculator->recalculate();

        /** @var GetPuzzleDifficulty $query */
        $query = $container->get(GetPuzzleDifficulty::class);
        $this->query = $query;
    }

    public function testByPuzzleIdReturnsResult(): void
    {
        $result = $this->query->byPuzzleId(PuzzleFixture::PUZZLE_500_01);

        self::assertNotNull($result);
        self::assertSame(PuzzleFixture::PUZZLE_500_01, $result->puzzleId);
        self::assertNotNull($result->difficultyScore);
        self::assertNotNull($result->difficultyTier);
        self::assertNotSame(MetricConfidence::Insufficient, $result->confidence);
    }

    public function testByPuzzleIdReturnsNullForPuzzleWithoutDifficulty(): void
    {
        $result = $this->query->byPuzzleId(PuzzleFixture::PUZZLE_9000);

        // Puzzle with no solves will either have no record or an insufficient one
        self::assertTrue(
            $result === null || $result->difficultyScore === null,
            'Puzzle with no solves should have no difficulty score',
        );
    }

    public function testForPuzzleListReturnsBatch(): void
    {
        $results = $this->query->forPuzzleList([
            PuzzleFixture::PUZZLE_500_01,
            PuzzleFixture::PUZZLE_500_02,
            PuzzleFixture::PUZZLE_9000,
        ]);

        // At least PUZZLE_500_01 should be in results
        self::assertArrayHasKey(PuzzleFixture::PUZZLE_500_01, $results);
        self::assertNotNull($results[PuzzleFixture::PUZZLE_500_01]->difficultyScore);
    }

    public function testForPuzzleListWithEmptyArrayReturnsEmpty(): void
    {
        $results = $this->query->forPuzzleList([]);

        self::assertSame([], $results);
    }

    public function testIntelPuzzleAHasDifficulty(): void
    {
        $result = $this->query->byPuzzleId(PuzzleIntelligenceFixture::INTEL_PUZZLE_A);

        self::assertNotNull($result);
        self::assertNotNull($result->difficultyScore);
        self::assertGreaterThanOrEqual(5, $result->sampleSize);
    }
}
