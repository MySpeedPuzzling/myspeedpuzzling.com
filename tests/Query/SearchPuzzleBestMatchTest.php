<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Query;

use SpeedPuzzling\Web\Query\SearchPuzzle;
use SpeedPuzzling\Web\Results\PuzzleOverview;
use SpeedPuzzling\Web\Tests\ChangesPuzzleRecords;
use SpeedPuzzling\Web\Tests\DataFixtures\PuzzleFixture;
use SpeedPuzzling\Web\Value\PiecesRange;
use SpeedPuzzling\Web\Value\PuzzleNames;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * The catalogue search on the search keys (PuzzleTextSearch): every name of a puzzle matches, folded like the keys
 * (accents, case, full-width forms), and "best match" orders by the six tiers of docs/features/puzzle-names/README.md.
 */
final class SearchPuzzleBestMatchTest extends KernelTestCase
{
    use ChangesPuzzleRecords;

    private SearchPuzzle $searchPuzzle;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->searchPuzzle = self::getContainer()->get(SearchPuzzle::class);
    }

    public function testBestMatchOrdersByTier(): void
    {
        // None of them is solved, so only the tier tells them apart
        self::changePuzzleBrandCode(PuzzleFixture::PUZZLE_9000, 'TIGER');                    // 6 the exact brand code
        self::renamePuzzle(PuzzleFixture::PUZZLE_6000, 'Puzzle 18', self::names('Tiger'));   // 5 a whole (other) name
        self::renamePuzzle(PuzzleFixture::PUZZLE_5000, 'Tiger Lily');                         // 4 a name starts with it
        self::renamePuzzle(PuzzleFixture::PUZZLE_4000, 'White Tiger Cub');                    // 3 a word starts with it
        self::renamePuzzle(PuzzleFixture::PUZZLE_3000, 'Sabertiger');                         // 2 a name contains it
        self::changePuzzleBrandCode(PuzzleFixture::PUZZLE_2000, 'XTIGER9');                   // 1 a part of a code

        $expected = [
            PuzzleFixture::PUZZLE_9000,
            PuzzleFixture::PUZZLE_6000,
            PuzzleFixture::PUZZLE_5000,
            PuzzleFixture::PUZZLE_4000,
            PuzzleFixture::PUZZLE_3000,
            PuzzleFixture::PUZZLE_2000,
        ];

        self::assertSame($expected, $this->search('tiger'));
        // Folded like the keys: case, accents, full-width letters
        self::assertSame($expected, $this->search('TÍGER'));
        self::assertSame($expected, $this->search('ｔｉｇｅｒ'));

        // Every other sort keeps the match as the tiebreak only
        self::assertSame(
            [PuzzleFixture::PUZZLE_2000, PuzzleFixture::PUZZLE_6000, PuzzleFixture::PUZZLE_9000, PuzzleFixture::PUZZLE_3000, PuzzleFixture::PUZZLE_5000, PuzzleFixture::PUZZLE_4000],
            $this->search('tiger', 'a-z'),
        );
    }

    public function testAWholeNameComesBeforeTheNamesStartingWithIt(): void
    {
        // "Puzzle 1" is a whole name, "Puzzle 10".."Puzzle 19" start with it
        $found = $this->search('puzzle 1');

        self::assertSame(PuzzleFixture::PUZZLE_500_01, $found[0]);
        self::assertContains(PuzzleFixture::PUZZLE_1000_05, $found);
        self::assertNotContains(PuzzleFixture::PUZZLE_500_02, $found);
    }

    public function testEveryNameOfAPuzzleIsSearched(): void
    {
        // PUZZLE_1000_02 is also "Kouzelná zahrada" (cs) and "Zauberhafter Garten" (de), PUZZLE_300 "Kouzelna zahrada"
        self::assertEqualsCanonicalizing([PuzzleFixture::PUZZLE_1000_02, PuzzleFixture::PUZZLE_300], $this->search('kouzelná'));
        self::assertEqualsCanonicalizing([PuzzleFixture::PUZZLE_1000_02, PuzzleFixture::PUZZLE_300], $this->search('KOUZELNA ZAHRADA'));
        self::assertSame([PuzzleFixture::PUZZLE_1000_02], $this->search('zauberhafter garten'));
    }

    public function testTwoLetterAndJapaneseQueries(): void
    {
        // Two letters: a part of a name, or a whole brand code
        self::changePuzzleBrandCode(PuzzleFixture::PUZZLE_9000, 'NA');
        self::assertSame(
            [PuzzleFixture::PUZZLE_9000],
            array_slice($this->search('na'), 0, 1),
        );
        self::assertEqualsCanonicalizing(
            [PuzzleFixture::PUZZLE_9000, PuzzleFixture::PUZZLE_1000_02, PuzzleFixture::PUZZLE_300],
            $this->search('ná'),
        );

        // PUZZLE_HIDDEN_IMAGE is also 魔法の庭 (ja)
        self::assertSame([PuzzleFixture::PUZZLE_HIDDEN_IMAGE], $this->search('魔法'));
        self::assertSame([PuzzleFixture::PUZZLE_HIDDEN_IMAGE], $this->search('魔法の庭'));
    }

    public function testTypedWildcardsAreLiteralAlsoInFullWidth(): void
    {
        self::renamePuzzle(PuzzleFixture::PUZZLE_1500_01, '100% Cotton');
        self::renamePuzzle(PuzzleFixture::PUZZLE_1500_02, 'Under_score');
        self::renamePuzzle(PuzzleFixture::PUZZLE_2000, 'Back\\slash');

        self::assertSame([PuzzleFixture::PUZZLE_1500_01], $this->search('%'));
        self::assertSame([PuzzleFixture::PUZZLE_1500_01], $this->search('％'));
        self::assertSame([PuzzleFixture::PUZZLE_1500_02], $this->search('_'));
        self::assertSame([PuzzleFixture::PUZZLE_1500_02], $this->search('＿'));
        self::assertSame([PuzzleFixture::PUZZLE_2000], $this->search('\\'));
        self::assertSame([PuzzleFixture::PUZZLE_2000], $this->search('＼'));
        self::assertSame([], $this->search('abc\\'));
    }

    public function testNothingTypedIsNoTextFilter(): void
    {
        $everything = $this->searchPuzzle->countByUserInput(null, null, PiecesRange::any(), null);

        foreach (['', '   ', "\t\u{200B}"] as $search) {
            self::assertSame($everything, $this->searchPuzzle->countByUserInput(null, $search, PiecesRange::any(), null));
        }
    }

    /**
     * Count and page share the condition: the count is the number of rows over all pages, none twice
     */
    public function testCountAgreesWithEveryPage(): void
    {
        foreach (['', 'a', 'puzzle', 'ná', '1', '％', '4005556', '99999', '魔法', 'xyzqw'] as $search) {
            foreach (['best-match', 'most-solved', 'a-z'] as $sort) {
                $ids = [];

                for ($offset = 0; $offset < 200; $offset += 7) {
                    $page = $this->searchPuzzle->byUserInput(null, $search, PiecesRange::any(), null, $sort, $offset, 7);
                    $ids = [...$ids, ...self::ids($page)];

                    if (count($page) < 7) {
                        break;
                    }
                }

                self::assertSame(array_unique($ids), $ids, "\"$search\" by $sort");
                self::assertCount($this->searchPuzzle->countByUserInput(null, $search, PiecesRange::any(), null), $ids, "\"$search\" by $sort");
            }
        }
    }

    /**
     * @return list<string>
     */
    private function search(string $search, string $sort = 'best-match'): array
    {
        return self::ids($this->searchPuzzle->byUserInput(null, $search, PiecesRange::any(), null, $sort, 0, 100));
    }

    private static function names(string ...$names): PuzzleNames
    {
        return PuzzleNames::fromArray(array_map(static fn (string $name): array => ['name' => $name, 'language' => null], $names));
    }

    /**
     * @param array<PuzzleOverview> $puzzles
     *
     * @return list<string>
     */
    private static function ids(array $puzzles): array
    {
        return array_values(array_map(static fn (PuzzleOverview $puzzle): string => $puzzle->puzzleId, $puzzles));
    }
}
