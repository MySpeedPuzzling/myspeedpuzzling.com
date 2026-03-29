<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Query;

use SpeedPuzzling\Web\Query\GetPlayerBaselineProgress;
use SpeedPuzzling\Web\Services\PuzzleIntelligence\PuzzleIntelligenceRecalculator;
use SpeedPuzzling\Web\Tests\DataFixtures\PlayerFixture;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class GetPlayerBaselineProgressTest extends KernelTestCase
{
    private GetPlayerBaselineProgress $query;

    protected function setUp(): void
    {
        self::bootKernel();
        $container = self::getContainer();

        /** @var PuzzleIntelligenceRecalculator $recalculator */
        $recalculator = $container->get(PuzzleIntelligenceRecalculator::class);
        $recalculator->recalculate();

        /** @var GetPlayerBaselineProgress $query */
        $query = $container->get(GetPlayerBaselineProgress::class);
        $this->query = $query;
    }

    public function testCurrentBaselineReturnsValueForPlayerWithData(): void
    {
        $baseline = $this->query->currentBaseline(PlayerFixture::PLAYER_REGULAR, 500);

        self::assertNotNull($baseline);
        self::assertGreaterThan(0, $baseline);
    }

    public function testCurrentBaselineReturnsNullForUnknownPlayer(): void
    {
        $baseline = $this->query->currentBaseline('00000000-0000-0000-0000-000000000099', 500);

        self::assertNull($baseline);
    }

    public function testBaselineAtPercentileReturnsValue(): void
    {
        $baseline = $this->query->baselineAtPercentile(500, 50.0);

        self::assertNotNull($baseline);
        self::assertGreaterThan(0, $baseline);
    }

    public function testFasterPercentileHasLowerBaseline(): void
    {
        $baseline50 = $this->query->baselineAtPercentile(500, 50.0);
        $baseline85 = $this->query->baselineAtPercentile(500, 85.0);

        if ($baseline50 === null || $baseline85 === null) {
            self::markTestSkipped('Not enough data for percentile comparison');
        }

        self::assertLessThanOrEqual($baseline50, $baseline85);
    }

    public function testSolveProgressReturnsDataForPlayer(): void
    {
        $progress = $this->query->solveProgress(PlayerFixture::PLAYER_REGULAR);

        self::assertNotEmpty($progress);
        self::assertArrayHasKey(500, $progress);
        self::assertGreaterThan(0, $progress[500]['baseline_solves']);
    }

    public function testSolveProgressReturnsEmptyForUnknownPlayer(): void
    {
        $progress = $this->query->solveProgress('00000000-0000-0000-0000-000000000099');

        self::assertEmpty($progress);
    }
}
