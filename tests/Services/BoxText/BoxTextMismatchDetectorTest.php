<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Services\BoxText;

use PHPUnit\Framework\TestCase;
use SpeedPuzzling\Web\Services\BoxText\BoxTextMismatchDetector;
use SpeedPuzzling\Web\Value\BoxTextCatalogue;
use SpeedPuzzling\Web\Value\BoxTextField;

/**
 * docs/features/puzzle-approvals.md, "Box-text mismatches": what the box says vs the record - codes compared the way
 * the catalogue stores them, nothing read is no mismatch.
 */
final class BoxTextMismatchDetectorTest extends TestCase
{
    private const string PUZZLE = '018d0003-0000-0000-0000-000000000001';

    public function testNothingReadIsNoMismatch(): void
    {
        self::assertSame([], BoxTextMismatchDetector::detect(self::PUZZLE, [], self::catalogue()));
        self::assertSame([], BoxTextMismatchDetector::detect(self::PUZZLE, ['pieces' => [], 'eans' => [], 'productCodes' => [], 'brandMentions' => [], 'multiPack' => null], self::catalogue()));
    }

    public function testPiecesAreComparedAsNumbers(): void
    {
        $box = ['pieces' => [['v' => 1000, 'evidence' => '1000 Teile'], ['v' => 500, 'evidence' => '500 pieces']]];

        self::assertSame([], BoxTextMismatchDetector::detect(self::PUZZLE, $box, self::catalogue(pieces: 500)), 'one of the values is the catalogue\'s');

        $mismatches = BoxTextMismatchDetector::detect(self::PUZZLE, $box, self::catalogue(pieces: 1500));
        self::assertCount(1, $mismatches);
        self::assertSame(BoxTextField::Pieces, $mismatches[0]->field);
        self::assertSame(['500', '1000'], $mismatches[0]->boxValues);
        self::assertSame('500, 1000', $mismatches[0]->valueKey());
        self::assertSame('1500', $mismatches[0]->catalogueValue);
        self::assertSame(['1000 Teile', '500 pieces'], $mismatches[0]->evidence);
    }

    public function testAMultiPackFitsEachPuzzleAndTheirSum(): void
    {
        $box = ['pieces' => [['v' => 500, 'evidence' => '500']], 'multiPack' => ['count' => 2, 'pieces' => 500]];

        self::assertSame([], BoxTextMismatchDetector::detect(self::PUZZLE, $box, self::catalogue(pieces: 1000)));
        self::assertSame([], BoxTextMismatchDetector::detect(self::PUZZLE, $box, self::catalogue(pieces: 500)));
        self::assertCount(1, BoxTextMismatchDetector::detect(self::PUZZLE, $box, self::catalogue(pieces: 300)));
    }

    public function testEansAreComparedAsTheCatalogueStoresThem(): void
    {
        // A UPC-A read with its leading zero is the catalogue's 11 digits; the box's EAN among several of the catalogue's
        self::assertSame([], BoxTextMismatchDetector::detect(self::PUZZLE, ['eans' => ['019962194016']], self::catalogue(ean: '19962194016')));
        self::assertSame([], BoxTextMismatchDetector::detect(self::PUZZLE, ['eans' => ['4005556173709']], self::catalogue(ean: '4005555001997, 4005556173709')));

        $mismatches = BoxTextMismatchDetector::detect(self::PUZZLE, ['eans' => ['4005556173709']], self::catalogue(ean: '4005556123456'));
        self::assertCount(1, $mismatches);
        self::assertSame(BoxTextField::Ean, $mismatches[0]->field);
        self::assertSame(['4005556173709'], $mismatches[0]->boxValues);
        self::assertSame('4005556123456', $mismatches[0]->catalogueValue);

        // An empty catalogue field with a code on the box is a mismatch too
        $missing = BoxTextMismatchDetector::detect(self::PUZZLE, ['eans' => ['4005556173709']], self::catalogue(ean: null));
        self::assertCount(1, $missing);
        self::assertNull($missing[0]->catalogueValue);
    }

    public function testBrandCodesIgnoreCaseSeparatorsAndThePrintedCheckDigit(): void
    {
        self::assertSame([], BoxTextMismatchDetector::detect(self::PUZZLE, ['productCodes' => ['rb-500-001']], self::catalogue(brandCodes: 'RB-500-001')));
        self::assertSame([], BoxTextMismatchDetector::detect(self::PUZZLE, ['productCodes' => ['12 000 199']], self::catalogue(brandCodes: '12000199')));
        // Ravensburger prints the EAN's check digit after the code: 17370 + 9 of 4005556173709
        self::assertSame([], BoxTextMismatchDetector::detect(self::PUZZLE, ['productCodes' => ['173709']], self::catalogue(ean: '4005556173709', brandCodes: '17370')));

        $mismatches = BoxTextMismatchDetector::detect(self::PUZZLE, ['productCodes' => ['17371', '17370-1']], self::catalogue(brandCodes: '17370'));
        self::assertCount(1, $mismatches);
        self::assertSame(BoxTextField::BrandCode, $mismatches[0]->field);
        self::assertSame(['17370-1', '17371'], $mismatches[0]->boxValues);
    }

    public function testBrandNamesIgnoreCaseAndSpacing(): void
    {
        self::assertSame([], BoxTextMismatchDetector::detect(self::PUZZLE, ['brandMentions' => ['Disney', ' ravensburger ']], self::catalogue(brand: 'Ravensburger')));

        $mismatches = BoxTextMismatchDetector::detect(self::PUZZLE, ['brandMentions' => ['Trefl', 'Clementoni']], self::catalogue(brand: 'Ravensburger'));
        self::assertCount(1, $mismatches);
        self::assertSame(['Clementoni', 'Trefl'], $mismatches[0]->boxValues);
        self::assertSame('Ravensburger', $mismatches[0]->catalogueValue);
    }

    public function testAPayloadIsNeverTrusted(): void
    {
        $box = ['pieces' => [['v' => '1000'], 'junk', ['evidence' => 'x']], 'eans' => 'not a list', 'productCodes' => [42], 'brandMentions' => [str_repeat('x', 200)]];

        self::assertSame([], BoxTextMismatchDetector::detect(self::PUZZLE, $box, self::catalogue()));
    }

    public function testStoredValuesAreReCheckedAgainstTheRecordAsItIsNow(): void
    {
        $mismatches = BoxTextMismatchDetector::detect(self::PUZZLE, ['eans' => ['4005556173709']], self::catalogue(ean: null));
        self::assertCount(1, $mismatches);
        $stored = $mismatches[0];

        self::assertTrue(BoxTextMismatchDetector::stillMismatched(BoxTextField::Ean, $stored->boxValues, self::catalogue(ean: null)));
        self::assertFalse(BoxTextMismatchDetector::stillMismatched(BoxTextField::Ean, $stored->boxValues, self::catalogue(ean: '4005556173709')));
        self::assertFalse(BoxTextMismatchDetector::stillMismatched(BoxTextField::Pieces, ['1000'], self::catalogue(pieces: 1000)));
        self::assertTrue(BoxTextMismatchDetector::stillMismatched(BoxTextField::Pieces, ['1000'], self::catalogue(pieces: 999)));
    }

    private static function catalogue(int $pieces = 500, null|string $ean = null, null|string $brandCodes = null, null|string $brand = 'Ravensburger'): BoxTextCatalogue
    {
        return new BoxTextCatalogue($pieces, $ean, $brandCodes, $brand);
    }
}
