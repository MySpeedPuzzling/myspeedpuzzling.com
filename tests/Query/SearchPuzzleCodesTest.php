<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Query;

use Doctrine\DBAL\Connection;
use SpeedPuzzling\Web\Query\FindPuzzlesByExactEan;
use SpeedPuzzling\Web\Query\GetMultiscanCandidates;
use SpeedPuzzling\Web\Query\SearchPuzzle;
use SpeedPuzzling\Web\Results\AutocompletePuzzle;
use SpeedPuzzling\Web\Results\PuzzleOverview;
use SpeedPuzzling\Web\Tests\ChangesPuzzleRecords;
use SpeedPuzzling\Web\Tests\DataFixtures\CompetitionFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\ManufacturerFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\PuzzleFixture;
use SpeedPuzzling\Web\Value\Ean;
use SpeedPuzzling\Web\Value\PiecesRange;
use SpeedPuzzling\Web\Value\PuzzleNames;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Codes on the search key `search_codes` (PuzzleTextSearch): a part of a code matches only from 5 letters/digits on,
 * a shorter search a whole code only; EANs without leading zeros - never without trailing ones ("1000" used to become
 * "1"). Barcode lookups (the scanners, multiscan, the API's `ean`) are exact: one of the puzzle's EANs, never a longer
 * code that contains it, never a brand code.
 */
final class SearchPuzzleCodesTest extends KernelTestCase
{
    use ChangesPuzzleRecords;

    private SearchPuzzle $searchPuzzle;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->searchPuzzle = self::getContainer()->get(SearchPuzzle::class);
    }

    public function testShortNumberMatchesNamesOnly(): void
    {
        // PUZZLE_1000_01 carries the brand code RB-1000-001, plenty of fixture EANs contain a "1"
        self::renamePuzzle(PuzzleFixture::PUZZLE_9000, 'Kingdom 1000');

        self::assertSame([PuzzleFixture::PUZZLE_9000], $this->search('1000'));
    }

    public function testExactEanRanksBeforeALongerCodeContainingIt(): void
    {
        // Neither has a solve, so the match decides - by name PUZZLE_1000_05 ("Puzzle 10") would come first
        self::changePuzzleEan(PuzzleFixture::PUZZLE_4000, '4005556999990');
        self::changePuzzleEan(PuzzleFixture::PUZZLE_1000_05, '40055569999904, 4005556000017');

        self::assertSame([PuzzleFixture::PUZZLE_4000, PuzzleFixture::PUZZLE_1000_05], $this->search('4005556999990'));
        self::assertSame([PuzzleFixture::PUZZLE_4000, PuzzleFixture::PUZZLE_1000_05], $this->search('04005556999990'));
        // Printed with spaces or dashes
        self::assertSame([PuzzleFixture::PUZZLE_4000, PuzzleFixture::PUZZLE_1000_05], $this->search('4 005556 999990'));
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
        self::changePuzzleBrandCode(PuzzleFixture::PUZZLE_9000, 'AB12');

        self::assertSame([PuzzleFixture::PUZZLE_9000], $this->search('ab12'));
        self::assertSame([PuzzleFixture::PUZZLE_9000], $this->search('AB-12'));
        self::assertSame([], $this->search('ab1'));
        // Typed wildcards are literal
        self::assertSame([], $this->search('AB_2'));
        self::assertSame([], $this->search('AB%'));
    }

    public function testBrandCodeEqualToAnEanIsFoundBySearchButNotByTheScanner(): void
    {
        self::changePuzzleBrandCode(PuzzleFixture::PUZZLE_9000, PuzzleFixture::EAN_PUZZLE_300);

        // Both are an exact code - the search lists both, the solved one first
        self::assertEqualsCanonicalizing([PuzzleFixture::PUZZLE_300, PuzzleFixture::PUZZLE_9000], $this->search(PuzzleFixture::EAN_PUZZLE_300));

        self::assertSame([PuzzleFixture::PUZZLE_300], self::ids($this->searchPuzzle->allByEan(PuzzleFixture::EAN_PUZZLE_300)));
        self::assertSame([PuzzleFixture::PUZZLE_300], $this->exactEanIds(PuzzleFixture::EAN_PUZZLE_300));
    }

    public function testBarcodeLookupIsExactWithLeadingZerosTolerated(): void
    {
        self::changePuzzleEan(PuzzleFixture::PUZZLE_1000_05, '4005556999990');
        // A longer code that contains the shared one
        self::changePuzzleEan(PuzzleFixture::PUZZLE_9000, '1' . PuzzleFixture::EAN_SHARED_4000_5000);

        $shared = [PuzzleFixture::PUZZLE_4000, PuzzleFixture::PUZZLE_5000];
        self::assertEqualsCanonicalizing($shared, self::ids($this->searchPuzzle->allByEan(PuzzleFixture::EAN_SHARED_4000_5000)));
        self::assertEqualsCanonicalizing($shared, self::ids($this->searchPuzzle->allByEan('0' . PuzzleFixture::EAN_SHARED_4000_5000)));
        self::assertEqualsCanonicalizing($shared, $this->exactEanIds(PuzzleFixture::EAN_SHARED_4000_5000));

        // "400555699999" (trailing zero stripped) used to find the 4005556999996 of PUZZLE_4000 and PUZZLE_5000 too
        self::assertSame([PuzzleFixture::PUZZLE_1000_05], self::ids($this->searchPuzzle->allByEan('4005556999990')));
        // A part of a code is no barcode
        self::assertSame([], $this->searchPuzzle->allByEan('400555699999'));
        self::assertSame([], $this->searchPuzzle->allByEan('0000'));
        self::assertSame([], $this->searchPuzzle->allByEan('9999'));
        self::assertSame([], $this->searchPuzzle->allByEan('not a code'));
    }

    public function testEveryEanOfAListIsFoundByTheScanner(): void
    {
        // EANS_PUZZLE_1000_05 = "4005556174812, 4005556197484"
        foreach (['4005556174812', '4005556197484'] as $ean) {
            self::assertSame([PuzzleFixture::PUZZLE_1000_05], self::ids($this->searchPuzzle->allByEan($ean)), $ean);
            self::assertSame([PuzzleFixture::PUZZLE_1000_05], $this->exactEanIds($ean), $ean);
        }
    }

    public function testMultiscanCandidatesAreTheExactOnesMostSolvedFirst(): void
    {
        $candidates = self::getContainer()->get(GetMultiscanCandidates::class);

        // PUZZLE_4000 and PUZZLE_5000 share one code (an ambiguous scan)
        self::assertEqualsCanonicalizing(
            [PuzzleFixture::PUZZLE_4000, PuzzleFixture::PUZZLE_5000],
            self::ids($candidates->forEan(Ean::from(PuzzleFixture::EAN_SHARED_4000_5000))),
        );

        self::changePuzzleEan(PuzzleFixture::PUZZLE_9000, '1' . PuzzleFixture::EAN_SHARED_4000_5000);
        self::assertNotContains(PuzzleFixture::PUZZLE_9000, self::ids($candidates->forEan(Ean::from(PuzzleFixture::EAN_SHARED_4000_5000))));
    }

    public function testExactEanGuardSeesSecretPuzzles(): void
    {
        self::getContainer()->get(Connection::class)->executeStatement(
            "UPDATE puzzle SET hide_until = '2999-01-01 00:00:00' WHERE id = :id",
            ['id' => PuzzleFixture::PUZZLE_1500_01],
        );

        self::assertSame([], $this->searchPuzzle->allByEan(PuzzleFixture::EAN_PUZZLE_1500_01));
        self::assertSame([PuzzleFixture::PUZZLE_1500_01], $this->exactEanIds(PuzzleFixture::EAN_PUZZLE_1500_01));
    }

    public function testPickerListsNoSecretPuzzleExceptTheCompetitionsOwnForItsOrganiser(): void
    {
        self::getContainer()->get(Connection::class)->executeStatement(
            "UPDATE puzzle SET hide_until = '2999-01-01 00:00:00' WHERE id = :id",
            ['id' => PuzzleFixture::PUZZLE_500_02],
        );

        self::assertNotContains(PuzzleFixture::PUZZLE_500_02, self::ids($this->searchPuzzle->byBrandId(ManufacturerFixture::MANUFACTURER_RAVENSBURGER)));
        self::assertContains(PuzzleFixture::PUZZLE_500_01, self::ids($this->searchPuzzle->byBrandId(ManufacturerFixture::MANUFACTURER_RAVENSBURGER)));

        // In the WJPC 2024 qualification round - not in any round of the Czech nationals
        self::assertContains(PuzzleFixture::PUZZLE_500_02, self::ids($this->searchPuzzle->byBrandId(ManufacturerFixture::MANUFACTURER_RAVENSBURGER, CompetitionFixture::COMPETITION_WJPC_2024)));
        self::assertNotContains(PuzzleFixture::PUZZLE_500_02, self::ids($this->searchPuzzle->byBrandId(ManufacturerFixture::MANUFACTURER_RAVENSBURGER, CompetitionFixture::COMPETITION_CZECH_NATIONALS_2024)));

        // Unapproved puzzles are listed
        self::assertContains(PuzzleFixture::PUZZLE_UNAPPROVED, self::ids($this->searchPuzzle->byBrandId(ManufacturerFixture::MANUFACTURER_UNAPPROVED)));
    }

    public function testPickerIsOrderedByTheMainTitle(): void
    {
        // An other name used to sort the puzzle in its place ("Aaa…" before "Puzzle 1")
        self::renamePuzzle(PuzzleFixture::PUZZLE_1500_02, 'Zebra crossing', PuzzleNames::fromArray([['name' => 'Aaa first', 'language' => null]]));

        $names = array_map(
            static fn (AutocompletePuzzle $puzzle): string => $puzzle->puzzleName,
            $this->searchPuzzle->byBrandId(ManufacturerFixture::MANUFACTURER_TREFL),
        );

        self::assertSame('Zebra crossing', end($names));
    }

    /**
     * The page in the catalogue's order for a typed term (best match) - asserted to agree with the count.
     *
     * @return list<string>
     */
    private function search(string $search): array
    {
        $ids = self::ids($this->searchPuzzle->byUserInput(null, $search, PiecesRange::any(), null, 'best-match', 0, 100));

        self::assertSame(count($ids), $this->searchPuzzle->countByUserInput(null, $search, PiecesRange::any(), null), "count of \"$search\"");

        return $ids;
    }

    /**
     * @return list<string>
     */
    private function exactEanIds(string $ean): array
    {
        return self::getContainer()->get(FindPuzzlesByExactEan::class)->ids(Ean::from($ean));
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
