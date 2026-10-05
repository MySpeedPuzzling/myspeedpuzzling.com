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
     */
    #[DataProvider('storedValues')]
    public function testStoredValueIsReadAsTheCanonicalList(null|string $stored, array $codes, null|string $toStored, bool $formatOnly): void
    {
        $list = BrandCodeList::fromStored($stored);

        self::assertSame($codes, $list->codes());
        self::assertSame($codes, $list->display());
        self::assertSame($toStored, $list->toStored());
        self::assertSame($formatOnly, $list->isFormatOnlyChangeOf($stored));
    }

    /**
     * @return iterable<string, array{null|string, list<string>, null|string, bool}>
     */
    public static function storedValues(): iterable
    {
        yield 'nothing' => [null, [], null, true];
        yield 'canonical already' => ['14709, 12000-199', ['14709', '12000-199'], '14709, 12000-199', true];
        yield 'spaces around codes' => [' 14709 , 12000199 ', ['14709', '12000199'], '14709, 12000199', true];
        yield 'lower case' => ['rb 14709', ['RB 14709'], 'RB 14709', true];
        yield 'whitespace inside collapsed' => ['17  331 p', ['17 331 P'], '17 331 P', true];
        yield 'a slash belongs to the code' => ['12000/199', ['12000/199'], '12000/199', true];
        yield 'the same code twice, once without its dash' => ['6500-5354,  6500-5354, 65005354', ['6500-5354'], '6500-5354', true];
        yield 'other separators' => ['482;239|17', ['482', '239', '17'], '482, 239, 17', true];
        yield 'full-width letters and digits' => ['ＲＢ１４７０９', ['RB14709'], 'RB14709', true];
        yield 'a lone dash is nothing' => ['-', [], null, true];
    }

    public function testInputsAreOneCodeEachAndBlankOnesAreDropped(): void
    {
        self::assertSame('14709, 12000199', BrandCodeList::fromInputs(['14709', '', null, ' 12000199 ', '14709'])->toStored());
        self::assertNull(BrandCodeList::fromInputs(['', ' '])->toStored());
    }

    public function testUnionKeepsTheFirstListsCodesFirstAndEveryCodeOnce(): void
    {
        $union = BrandCodeList::fromStored('KEEP-ME, 14709')->union(BrandCodeList::fromStored('keep me, SECOND-EDITION'));

        self::assertSame('KEEP-ME, 14709, SECOND-EDITION', $union->toStored());
    }
}
