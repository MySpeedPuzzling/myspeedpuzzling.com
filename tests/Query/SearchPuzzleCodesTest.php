<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Query;

use Doctrine\DBAL\Connection;
use SpeedPuzzling\Web\Query\SearchPuzzle;
use SpeedPuzzling\Web\Results\AutocompletePuzzle;
use SpeedPuzzling\Web\Results\PuzzleOverview;
use SpeedPuzzling\Web\Tests\DataFixtures\CompetitionFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\ManufacturerFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\PuzzleFixture;
use SpeedPuzzling\Web\Value\PiecesRange;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Codes (brand code, EAN) match as a part only from 5 letters/digits on, a shorter search only a whole code
 * (PuzzleCodeSearch); EANs without leading zeros - never without trailing ones ("1000" used to become "1").
 */
final class SearchPuzzleCodesTest extends KernelTestCase
{
    private SearchPuzzle $searchPuzzle;
    private Connection $database;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->searchPuzzle = self::getContainer()->get(SearchPuzzle::class);
        $this->database = self::getContainer()->get(Connection::class);
    }

    public function testShortNumberMatchesNamesOnly(): void
    {
        // PUZZLE_1000_01 carries the brand code RB-1000-001, plenty of fixture EANs contain a "1"
        $this->update(PuzzleFixture::PUZZLE_9000, ['name' => 'Kingdom 1000']);

        self::assertSame([PuzzleFixture::PUZZLE_9000], $this->search('1000'));
    }

    public function testExactEanEndingInZeroRanksFirst(): void
    {
        // Neither has a solve, so the match decides - by name PUZZLE_1000_05 ("Puzzle 10") would come first
        $this->update(PuzzleFixture::PUZZLE_4000, ['ean' => '4005556999990']);
        $this->update(PuzzleFixture::PUZZLE_1000_05, ['ean' => '4005556999990, 4005556000017']);

        self::assertSame([PuzzleFixture::PUZZLE_4000, PuzzleFixture::PUZZLE_1000_05], $this->search('4005556999990'));
        self::assertSame([PuzzleFixture::PUZZLE_4000, PuzzleFixture::PUZZLE_1000_05], $this->search('04005556999990'));
    }

    public function testPartOfACodeMatchesFromFiveLettersOrDigits(): void
    {
        // EAN_SHARED_4000_5000 = 4005556999996
        self::assertSame([PuzzleFixture::PUZZLE_4000, PuzzleFixture::PUZZLE_5000], $this->search('99999'));
        self::assertSame([], $this->search('9999'));

        self::assertSame([PuzzleFixture::PUZZLE_1000_01], $this->search('RB-1000'));
        self::assertSame([], $this->search('B-10'));
    }

    public function testShortBrandCodeMatchesTheWholeCodeOnly(): void
    {
        $this->update(PuzzleFixture::PUZZLE_9000, ['identification_number' => 'AB12']);

        self::assertSame([PuzzleFixture::PUZZLE_9000], $this->search('ab12'));
        self::assertSame([], $this->search('ab1'));
        // Typed wildcards are literal
        self::assertSame([], $this->search('AB_2'));
        self::assertSame([], $this->search('AB%'));
    }

    public function testBarcodeLookupStripsLeadingZerosOnly(): void
    {
        $this->update(PuzzleFixture::PUZZLE_4000, ['ean' => '4005556999990']);

        // "400555699999" (trailing zero stripped) used to find PUZZLE_5000's 4005556999996 too
        self::assertSame([PuzzleFixture::PUZZLE_4000], self::ids($this->searchPuzzle->allByEan('4005556999990')));
        self::assertSame([PuzzleFixture::PUZZLE_4000], self::ids($this->searchPuzzle->allByEan('04005556999990')));
        self::assertSame([PuzzleFixture::PUZZLE_5000], self::ids($this->searchPuzzle->allByEan(PuzzleFixture::EAN_SHARED_4000_5000)));
        self::assertSame([], $this->searchPuzzle->allByEan('0000'));
        self::assertSame([], $this->searchPuzzle->allByEan('9999'));
    }

    public function testPickerListsNoSecretPuzzleExceptTheCompetitionsOwnForItsOrganiser(): void
    {
        $this->update(PuzzleFixture::PUZZLE_500_02, ['hide_until' => '2999-01-01 00:00:00']);

        self::assertNotContains(PuzzleFixture::PUZZLE_500_02, self::ids($this->searchPuzzle->byBrandId(ManufacturerFixture::MANUFACTURER_RAVENSBURGER)));
        self::assertContains(PuzzleFixture::PUZZLE_500_01, self::ids($this->searchPuzzle->byBrandId(ManufacturerFixture::MANUFACTURER_RAVENSBURGER)));

        // In the WJPC 2024 qualification round - not in any round of the Czech nationals
        self::assertContains(PuzzleFixture::PUZZLE_500_02, self::ids($this->searchPuzzle->byBrandId(ManufacturerFixture::MANUFACTURER_RAVENSBURGER, CompetitionFixture::COMPETITION_WJPC_2024)));
        self::assertNotContains(PuzzleFixture::PUZZLE_500_02, self::ids($this->searchPuzzle->byBrandId(ManufacturerFixture::MANUFACTURER_RAVENSBURGER, CompetitionFixture::COMPETITION_CZECH_NATIONALS_2024)));

        // Unapproved puzzles are listed
        self::assertContains(PuzzleFixture::PUZZLE_UNAPPROVED, self::ids($this->searchPuzzle->byBrandId(ManufacturerFixture::MANUFACTURER_UNAPPROVED)));
    }

    /**
     * The page in its default order - asserted to agree with the count for the same input.
     *
     * @return list<string>
     */
    private function search(string $search): array
    {
        $ids = self::ids($this->searchPuzzle->byUserInput(null, $search, PiecesRange::any(), null, 'most-solved', 0, 100));

        self::assertSame(count($ids), $this->searchPuzzle->countByUserInput(null, $search, PiecesRange::any(), null), "count of \"$search\"");

        return $ids;
    }

    /**
     * @param array<string, string> $columns
     */
    private function update(string $puzzleId, array $columns): void
    {
        $assignments = implode(', ', array_map(static fn (string $column): string => "$column = :$column", array_keys($columns)));

        $this->database->executeStatement("UPDATE puzzle SET $assignments WHERE id = :id", [...$columns, 'id' => $puzzleId]);
    }

    /**
     * @param array<PuzzleOverview|AutocompletePuzzle> $puzzles
     *
     * @return list<string>
     */
    private static function ids(array $puzzles): array
    {
        return array_values(array_map(static fn (PuzzleOverview|AutocompletePuzzle $puzzle): string => $puzzle->puzzleId, $puzzles));
    }
}
