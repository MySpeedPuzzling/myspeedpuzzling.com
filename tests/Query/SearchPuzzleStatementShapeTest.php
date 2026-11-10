<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Query;

use SpeedPuzzling\Web\Query\SearchPuzzle;
use SpeedPuzzling\Web\Tests\DataFixtures\TagFixture;
use SpeedPuzzling\Web\Value\PiecesRange;
use Symfony\Bridge\Doctrine\Middleware\Debug\DebugDataHolder;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * The tag filter is a semi-join added only when a tag is chosen. A join on tag_puzzle would duplicate a puzzle per tag,
 * forcing DISTINCT ON / COUNT(DISTINCT) - a sort of every match by id before the real ORDER BY, which spilled to disk
 * for a one-letter header search (docs/features/puzzle-names/README.md, SearchPuzzleCharacterizationTest for the rows).
 */
final class SearchPuzzleStatementShapeTest extends KernelTestCase
{
    private SearchPuzzle $searchPuzzle;

    private DebugDataHolder $debugDataHolder;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->searchPuzzle = self::getContainer()->get(SearchPuzzle::class);

        $this->debugDataHolder = self::getContainer()->get('doctrine.debug_data_holder');
    }

    public function testWithoutATagNeitherTheTagsNorADistinctAreInTheStatements(): void
    {
        foreach ($this->statements(null) as $sql) {
            self::assertStringNotContainsString('tag_puzzle', $sql);
            self::assertStringNotContainsString('DISTINCT', $sql);
        }
    }

    public function testATagIsASemiJoinWithoutADistinct(): void
    {
        foreach ($this->statements(TagFixture::TAG_WJPC) as $sql) {
            self::assertMatchesRegularExpression('/puzzle\.id IN \(SELECT [a-z_]+\.puzzle_id FROM tag_puzzle/', $sql);
            self::assertStringNotContainsString('JOIN tag_puzzle', $sql);
            self::assertStringNotContainsString('DISTINCT', $sql);
        }
    }

    /**
     * @return list<string> the page and the count statement
     */
    private function statements(null|string $tag): array
    {
        $this->debugDataHolder->reset();

        $this->searchPuzzle->byUserInput(null, 'a', PiecesRange::any(), $tag, 'best-match', 0, 15);
        $this->searchPuzzle->countByUserInput(null, 'a', PiecesRange::any(), $tag);

        $statements = [];

        foreach ($this->debugDataHolder->getData() as $queries) {
            if (is_array($queries) === false) {
                continue;
            }

            foreach ($queries as $query) {
                if (is_array($query) && is_string($query['sql'] ?? null)) {
                    $statements[] = $query['sql'];
                }
            }
        }

        self::assertCount(2, $statements);

        return $statements;
    }
}
