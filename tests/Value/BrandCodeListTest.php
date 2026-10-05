<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Value;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use SpeedPuzzling\Web\Value\BrandCodeList;

final class BrandCodeListTest extends TestCase
{
    /**
     * @param list<string> $codes
     * @param list<string> $display
     */
    #[DataProvider('storedValues')]
    public function testStoredValueIsReadAsTheCanonicalList(null|string $stored, array $codes, array $display, null|string $toStored): void
    {
        $list = BrandCodeList::fromStored($stored);

        self::assertSame($codes, $list->codes());
        self::assertSame($display, $list->display());
        self::assertSame($toStored, $list->toStored());
        self::assertTrue($list->isFormatOnlyChangeOf($stored));
        self::assertTrue(BrandCodeList::fromStored($list->toStored())->equals($list));
    }

    /**
     * @return iterable<string, array{null|string, list<string>, list<string>, null|string}>
     */
    public static function storedValues(): iterable
    {
        yield 'nothing' => [null, [], [], null];
        yield 'canonical already' => ['14709, 12000-199', ['14709', '12000-199'], ['14709', '12000-199'], '14709, 12000-199'];
        yield 'spaces around codes' => [' 14709 , 12000199 ', ['14709', '12000199'], ['14709', '12000199'], '14709, 12000199'];
        yield 'lower case is shown as typed, stored in upper case' => ['rb 14709', ['RB 14709'], ['rb 14709'], 'RB 14709'];
        yield 'whitespace inside collapsed' => ['17  331 p', ['17 331 P'], ['17 331 p'], '17 331 P'];
        yield 'a slash belongs to the code' => ['12000/199', ['12000/199'], ['12000/199'], '12000/199'];
        yield 'the same code twice' => ['6500-5354,  6500-5354', ['6500-5354'], ['6500-5354'], '6500-5354'];
        yield 'codes differing in their separators are two codes' => ['12-345, 123-45, 12345', ['12-345', '123-45', '12345'], ['12-345', '123-45', '12345'], '12-345, 123-45, 12345'];
        yield 'other separators' => ['482;239|17', ['482', '239', '17'], ['482', '239', '17'], '482, 239, 17'];
        yield 'full-width letters and digits' => ['ＲＢ１４７０９', ['RB14709'], ['RB14709'], 'RB14709'];
        yield 'a lone dash is nothing' => ['-', [], [], null];
        yield 'a stray left-to-right mark goes' => ["\u{200E}3723-2", ['3723-2'], ['3723-2'], '3723-2'];
    }

    public function testACommaBetweenDigitsIsNoFormatChange(): void
    {
        self::assertTrue(BrandCodeList::hasAmbiguousComma('482,239'));
        self::assertFalse(BrandCodeList::fromStored('482,239')->isFormatOnlyChangeOf('482,239'));
        self::assertFalse(BrandCodeList::hasAmbiguousComma('482, 239'));
    }

    public function testInputsAreOneCodeEachAndBlankOnesAreDropped(): void
    {
        self::assertSame('14709, 12000199', BrandCodeList::fromInputs(['14709', '', null, ' 12000199 ', '14709'])->toStored());
        self::assertNull(BrandCodeList::fromInputs(['', ' '])->toStored());
    }

    public function testUnionKeepsTheFirstListsCodesFirstAndEveryCodeOnce(): void
    {
        $union = BrandCodeList::fromStored('KEEP-ME, 14709')->union(BrandCodeList::fromStored('keep-me, keep me, SECOND-EDITION'));

        self::assertSame('KEEP-ME, 14709, KEEP ME, SECOND-EDITION', $union->toStored());
    }

    public function testTheSameCodesInTheSameOrderAreEqualWhateverTheirCase(): void
    {
        self::assertTrue(BrandCodeList::fromStored('rb 14709, 17481')->equals(BrandCodeList::fromInputs(['RB  14709', '17481'])));
        self::assertFalse(BrandCodeList::fromStored('12-345')->equals(BrandCodeList::fromStored('123-45')));
    }
}
