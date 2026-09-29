<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Query;

use Doctrine\DBAL\Connection;
use SpeedPuzzling\Web\Query\SearchPuzzle;
use SpeedPuzzling\Web\Results\PuzzleOverview;
use SpeedPuzzling\Web\Services\PuzzleIntelligence\PuzzleIntelligenceRecalculator;
use SpeedPuzzling\Web\Tests\DataFixtures\PuzzleFixture;
use SpeedPuzzling\Web\Value\PiecesRange;
use SpeedPuzzling\Web\Value\PuzzleSearchCriteria;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * The difficulty filter of the puzzle database, including "not rated yet".
 */
final class SearchPuzzleDifficultyTest extends KernelTestCase
{
    private SearchPuzzle $searchPuzzle;

    private Connection $connection;

    protected function setUp(): void
    {
        self::bootKernel();
        self::getContainer()->get(PuzzleIntelligenceRecalculator::class)->recalculate();
        $this->searchPuzzle = self::getContainer()->get(SearchPuzzle::class);
        $this->connection = self::getContainer()->get(Connection::class);
    }

    public function testNotRatedYetMatchesPuzzlesWithoutATier(): void
    {
        $expected = $this->expectedIds('pd.difficulty_tier IS NULL');
        self::assertContains(PuzzleFixture::PUZZLE_9000, $expected);

        $this->assertFilterMatches([PuzzleSearchCriteria::UNRATED_DIFFICULTY], $expected);
    }

    public function testNotRatedYetCombinesWithTiers(): void
    {
        $tier = $this->connection->fetchOne('SELECT difficulty_tier FROM puzzle_difficulty WHERE puzzle_id = ?', [PuzzleFixture::PUZZLE_500_01]);
        self::assertIsInt($tier);

        $expected = $this->expectedIds('pd.difficulty_tier IS NULL OR pd.difficulty_tier = ' . $tier);
        self::assertContains(PuzzleFixture::PUZZLE_500_01, $expected);
        self::assertContains(PuzzleFixture::PUZZLE_9000, $expected);

        $this->assertFilterMatches([$tier, PuzzleSearchCriteria::UNRATED_DIFFICULTY], $expected);
        $this->assertFilterMatches([$tier], $this->expectedIds('pd.difficulty_tier = ' . $tier));
    }

    /**
     * @param list<int> $tiers
     * @param list<string> $expected
     */
    private function assertFilterMatches(array $tiers, array $expected): void
    {
        $ids = array_map(
            static fn (PuzzleOverview $puzzle): string => $puzzle->puzzleId,
            $this->searchPuzzle->byUserInput(null, null, PiecesRange::any(), null, 'a-z', 0, 500, $tiers),
        );
        sort($ids);

        self::assertSame($expected, $ids);
        self::assertSame(count($expected), $this->searchPuzzle->countByUserInput(null, null, PiecesRange::any(), null, $tiers));
    }

    /**
     * @return list<string>
     */
    private function expectedIds(string $condition): array
    {
        /** @var list<string> $ids */
        $ids = $this->connection->fetchFirstColumn(
            "SELECT p.id FROM puzzle p LEFT JOIN puzzle_difficulty pd ON pd.puzzle_id = p.id WHERE (p.hide_until IS NULL OR p.hide_until <= NOW()) AND ({$condition}) ORDER BY p.id",
        );

        return $ids;
    }
}
