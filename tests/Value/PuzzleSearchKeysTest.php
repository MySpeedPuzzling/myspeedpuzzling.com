<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Value;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use SpeedPuzzling\Web\Value\PuzzleNames;
use SpeedPuzzling\Web\Value\PuzzleSearchKeys;

final class PuzzleSearchKeysTest extends TestCase
{
    public function testNamesKeyHoldsEveryNameFoldedOnItsOwnLine(): void
    {
        $alternatives = PuzzleNames::fromArray([
            ['name' => 'Kruh barev: Mušle', 'language' => 'cs'],
            ['name' => 'Muscheln', 'language' => 'de'],
            ['name' => '貝殻', 'language' => 'ja'],
        ]);

        self::assertSame(
            "\ncircle of colors: seashells\nkruh barev: musle\nmuscheln\n貝殻\n",
            PuzzleSearchKeys::names('Circle of Colors: Seashells', $alternatives),
        );
    }

    public function testNamesKeyHoldsEachFoldedNameOnce(): void
    {
        $alternatives = PuzzleNames::fromArray([
            ['name' => 'Kouzelná zahrada', 'language' => 'cs'],
            ['name' => 'KOUZELNA ZAHRADA', 'language' => null],
            ['name' => "\u{200B}", 'language' => null],
            ['name' => 'Magic Garden', 'language' => null],
        ]);

        self::assertSame("\nmagic garden\nkouzelna zahrada\n", PuzzleSearchKeys::names('Magic  Garden', $alternatives));
    }

    public function testNamesKeyOfAPuzzleWithoutOtherNames(): void
    {
        self::assertSame("\nstrasse? strasse\n", PuzzleSearchKeys::names('Straße? Strasse', new PuzzleNames()));
    }

    #[DataProvider('codes')]
    public function testCodesKey(null|string $ean, null|string $identificationNumber, null|string $expected): void
    {
        self::assertSame($expected, PuzzleSearchKeys::codes($ean, $identificationNumber));
    }

    /**
     * @return iterable<string, array{null|string, null|string, null|string}>
     */
    public static function codes(): iterable
    {
        yield 'no codes' => [null, null, null];
        yield 'empty fields' => ['', ' , ', null];
        yield 'the README example' => [
            '4005556147090, 4005555001997',
            '14709, 12000-199',
            "\ne:4005556147090\ne:4005555001997\nc:14709\nc:12000199\n",
        ];
        yield 'an EAN ending in 0 keeps its zero' => ['4005556555550', null, "\ne:4005556555550\n"];
        yield 'leading zeros stripped (UPC-A)' => ['036000291452', null, "\ne:36000291452\n"];
        yield 'separators , ; / |' => ['4005556147090;4005555001997 / 5900511101010|4005556202027', null, "\ne:4005556147090\ne:4005555001997\ne:5900511101010\ne:4005556202027\n"];
        yield 'spaces, dashes and dots inside one number' => ['4 005556 147090, 400-5555-001997, 4.005556.202027', null, "\ne:4005556147090\ne:4005555001997\ne:4005556202027\n"];
        yield 'two numbers separated by a space only' => ['4005556147090 4005555001997', null, "\ne:4005556147090\ne:4005555001997\n"];
        yield 'two numbers around a lone dash' => ['4005556147090 - 4005555001997', null, "\ne:4005556147090\ne:4005555001997\n"];
        yield 'junk in the EAN field is a brand-code line, never digits' => ['X002ROECA7, None, -, 4005556147090', null, "\ne:4005556147090\nc:x002roeca7\nc:none\n"];
        yield 'full-width digits and separators' => ['４００５５５６１４７０９０，４００５５５５００１９９７', null, "\ne:4005556147090\ne:4005555001997\n"];
        yield 'the > printed after the digits under a barcode' => ['5012269036039>', null, "\ne:5012269036039\n"];
        yield 'digits of another script are no barcode' => ['٤٠٠٥٥٥٦١٤٧٠٩٠', null, null];
        yield 'only zeros is no code' => ['0000', null, null];
        yield 'duplicates once' => ['4005556147090, 04005556147090', 'RB-500, rb 500', "\ne:4005556147090\nc:rb500\n"];
        yield 'a brand code equal to an EAN is a c: line' => ['4005556147090', '4005556147090', "\ne:4005556147090\nc:4005556147090\n"];
        yield 'a brand code keeps its leading zeros' => [null, '00123', "\nc:00123\n"];
        yield 'brand codes split on , ; | but not on a slash' => [null, 'A-1; b/2 | c 3', "\nc:a1\nc:b2\nc:c3\n"];
        yield 'brand code letters folded' => [null, 'Ž-12 ｘ', "\nc:z12x\n"];
        yield 'a brand code of punctuation only is no code' => [null, '- / -', null];
    }
}
