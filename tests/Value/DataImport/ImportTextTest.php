<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Value\DataImport;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use SpeedPuzzling\Web\Value\DataImport\ImportText;

final class ImportTextTest extends TestCase
{
    #[DataProvider('cleaned')]
    public function testClean(string $text, string $expected): void
    {
        self::assertSame($expected, ImportText::clean($text));
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function cleaned(): iterable
    {
        yield 'plain text stays' => ['1:09:17', '1:09:17'];
        yield 'spaces around' => ['  Cozy Cabin  ', 'Cozy Cabin'];
        yield 'spaces inside become one' => ['Cozy    Cabin', 'Cozy Cabin'];
        yield 'a tab and a line break' => ["Cozy\tCabin\r\nLodge", 'Cozy Cabin Lodge'];
        yield 'a non-breaking space' => ["1\u{00A0}000", '1 000'];
        yield 'a narrow non-breaking space' => ["1\u{202F}000", '1 000'];
        yield 'an ideographic space' => ["500\u{3000}ピース", '500 ピース'];
        yield 'full-width digits and a colon' => ['１２：３４', '12:34'];
        yield 'full-width letters' => ['Ｙｅｓ', 'Yes'];
        yield 'half-width katakana' => ['ﾋﾟｰｽ', 'ピース'];
        yield 'a zero-width space' => ["1:09\u{200B}:17", '1:09:17'];
        yield 'direction marks' => ["\u{200E}2023-12-30\u{200F}", '2023-12-30'];
        yield 'a byte-order mark' => ["\u{FEFF}Title", 'Title'];
        yield 'a soft hyphen' => ["Ravens\u{00AD}burger", 'Ravensburger'];
        yield 'control characters' => ["a\u{0000}b\u{0007}c", 'abc'];
        yield 'the emoji variation mark' => ["✔\u{FE0F}", '✔'];
        yield 'case and accents stay' => ['Čas složení', 'Čas složení'];
        yield 'broken bytes' => ["Caf\xC3", 'Caf?'];
        yield 'nothing' => ['', ''];
        yield 'only spaces' => ["  \t\u{00A0} ", ''];
    }

    #[DataProvider('plain')]
    public function testPlain(string $cleaned, string $expected): void
    {
        self::assertSame($expected, ImportText::plain($cleaned));
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function plain(): iterable
    {
        yield 'lower case' => ['MARCH', 'march'];
        yield 'cs' => ['3. Března', '3. brezna'];
        yield 'de' => ['März', 'marz'];
        yield 'fr' => ['Août', 'aout'];
        yield 'es' => ['Sí', 'si'];
        yield 'a Japanese voiced mark is part of its letter' => ['ピース', 'ピース'];
        yield 'hiragana' => ['はい', 'はい'];
        yield 'digits and marks' => ['1:09:17 (PB)', '1:09:17 (pb)'];
    }

    public function testANumberAsAText(): void
    {
        self::assertSame('1000', ImportText::ofNumber(1000));
        self::assertSame('731.78', ImportText::ofNumber(731.78));
        self::assertSame('-5', ImportText::ofNumber(-5));
        self::assertSame('NaN', ImportText::ofNumber(NAN));
        self::assertSame('INF', ImportText::ofNumber(INF));
        self::assertSame('-INF', ImportText::ofNumber(-INF));
    }

    public function testTooLong(): void
    {
        self::assertFalse(ImportText::isTooLong(str_repeat('a', 200)));
        self::assertTrue(ImportText::isTooLong(str_repeat('a', 201)));
        self::assertFalse(ImportText::isTooLong(str_repeat('ž', 200)));
        self::assertTrue(ImportText::isTooLong(str_repeat('ž', 201)));
        self::assertFalse(ImportText::isTooLong(str_repeat('🧩', 200)));
        self::assertTrue(ImportText::isTooLong(str_repeat('🧩', 201)));
        self::assertTrue(ImportText::isTooLong(str_repeat('a', 5_000_000)));
        self::assertFalse(ImportText::isTooLong(''));
    }
}
