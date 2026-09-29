<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Query;

use SpeedPuzzling\Web\Query\GetPuzzleDifficulty;
use SpeedPuzzling\Web\Query\GetPuzzleListInsights;
use SpeedPuzzling\Web\Services\PuzzleIntelligence\PuzzleIntelligenceRecalculator;
use SpeedPuzzling\Web\Tests\DataFixtures\PuzzleFixture;
use Symfony\Bridge\Doctrine\Middleware\Debug\DebugDataHolder;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class GetPuzzleListInsightsTest extends KernelTestCase
{
    private GetPuzzleListInsights $query;

    protected function setUp(): void
    {
        self::bootKernel();
        self::getContainer()->get(PuzzleIntelligenceRecalculator::class)->recalculate();
        $this->query = self::getContainer()->get(GetPuzzleListInsights::class);
    }

    public function testWithDifficultyReturnsTiersAndSolveCounts(): void
    {
        $insights = $this->query->forPuzzles([PuzzleFixture::PUZZLE_500_01, PuzzleFixture::PUZZLE_9000, PuzzleFixture::PUZZLE_500_01], true);

        self::assertCount(2, $insights);

        $expectedTier = self::getContainer()->get(GetPuzzleDifficulty::class)->byPuzzleId(PuzzleFixture::PUZZLE_500_01)?->difficultyTier;
        self::assertNotNull($expectedTier);
        self::assertSame($expectedTier, $insights[PuzzleFixture::PUZZLE_500_01]->difficultyTier);
        self::assertGreaterThan(0, $insights[PuzzleFixture::PUZZLE_500_01]->solvedTimes);

        // Never solved: no statistics row, no tier
        self::assertSame(0, $insights[PuzzleFixture::PUZZLE_9000]->solvedTimes);
        self::assertNull($insights[PuzzleFixture::PUZZLE_9000]->difficultyTier);
    }

    public function testWithoutDifficultyKeepsSolveCountsAndNeverReadsDifficulty(): void
    {
        $holder = $this->debugDataHolder();

        $insights = $this->query->forPuzzles([PuzzleFixture::PUZZLE_500_01], false);

        self::assertNull($insights[PuzzleFixture::PUZZLE_500_01]->difficultyTier);
        self::assertGreaterThan(0, $insights[PuzzleFixture::PUZZLE_500_01]->solvedTimes);

        $queries = $this->executedSql($holder);
        self::assertCount(1, $queries);
        self::assertStringNotContainsString('puzzle_difficulty', $queries[0]);
    }

    public function testEmptyListRunsNoQuery(): void
    {
        $holder = $this->debugDataHolder();

        self::assertSame([], $this->query->forPuzzles([], true));
        self::assertSame([], $this->executedSql($holder));
    }

    private function debugDataHolder(): DebugDataHolder
    {
        /** @var DebugDataHolder $holder */
        $holder = self::getContainer()->get('doctrine.debug_data_holder');
        $holder->reset();

        return $holder;
    }

    /**
     * @return list<string>
     */
    private function executedSql(DebugDataHolder $holder): array
    {
        $sql = [];

        foreach ($holder->getData() as $connectionQueries) {
            if (is_array($connectionQueries) === false) {
                continue;
            }

            foreach ($connectionQueries as $query) {
                if (is_array($query) && is_string($query['sql'] ?? null)) {
                    $sql[] = $query['sql'];
                }
            }
        }

        return $sql;
    }
}
