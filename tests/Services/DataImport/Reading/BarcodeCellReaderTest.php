<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Services\DataImport\Reading;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use SpeedPuzzling\Web\Services\DataImport\Reading\BarcodeCellReader;
use SpeedPuzzling\Web\Tests\TestClock;
use SpeedPuzzling\Web\Value\DataImport\BarcodeCertainty;
use SpeedPuzzling\Web\Value\DataImport\BarcodeDamage;
use SpeedPuzzling\Web\Value\DataImport\CellInput;

/**
 * What a spreadsheet does to a barcode (docs/features/data-import/value-readers.md "Barcodes"). The codes are the
 * examples of EanListTest - valid check digits, nobody's data.
 */
final class BarcodeCellReaderTest extends TestCase
{
    private const string EAN = '4005556169320';
    private const string UPC = '036000291452';
    private const string UPC_STORED = '36000291452';

    #[DataProvider('oneBarcode')]
    public function testOneBarcode(CellInput $cell, string $code, string $printed): void
    {
        $read = (new BarcodeCellReader())->read($cell);

        self::assertSame(BarcodeCertainty::Barcodes, $read->certainty);
        self::assertSame([$code], $read->codes, 'Digits without leading zeros - how the catalogue stores and compares a barcode');
        self::assertSame([$printed], $read->printed());
        self::assertSame([], $read->other);
        self::assertNull($read->damage);
        self::assertSame($code, $read->eanList()->toStored());
    }

    /**
     * @return iterable<string, array{CellInput, string, string}>
     */
    public static function oneBarcode(): iterable
    {
        yield 'text' => [CellInput::text(self::EAN), self::EAN, self::EAN];
        yield 'a whole number' => [CellInput::number((int) self::EAN), self::EAN, self::EAN];
        yield 'the task\'s number stored as a float' => [CellInput::number(4005556169320.0), self::EAN, self::EAN];
        yield 'a number written out with a decimal' => [CellInput::text('4005556169320.0'), self::EAN, self::EAN];
        yield 'a number written out with two decimals and a comma' => [CellInput::text('4005556169320,00'), self::EAN, self::EAN];
        yield 'grouped as printed under the bars' => [CellInput::text('4 005556 169320'), self::EAN, self::EAN];
        yield 'spaces around' => [CellInput::text('  4005556169320  '), self::EAN, self::EAN];
        yield 'a non-breaking space and a direction mark' => [CellInput::text("\u{200E}4005556169320\u{00A0}"), self::EAN, self::EAN];
        yield 'full-width digits' => [CellInput::text('４００５５５６１６９３２０'), self::EAN, self::EAN];
        yield 'a GTIN-14 with a zero in front' => [CellInput::text('04005556169320'), self::EAN, self::EAN];
        yield 'a UPC-A as printed' => [CellInput::text(self::UPC), self::UPC_STORED, self::UPC];
        yield 'a UPC-A typed as a number: its leading zero is gone' => [CellInput::number((int) self::UPC_STORED), self::UPC_STORED, self::UPC];
        yield 'a UPC-A as a float' => [CellInput::number(36000291452.0), self::UPC_STORED, self::UPC];
        yield 'a UPC-A as text without its zero' => [CellInput::text(self::UPC_STORED), self::UPC_STORED, self::UPC];
        yield 'the same code twice' => [CellInput::text('4005556169320, 4005556169320'), self::EAN, self::EAN];
    }

    public function testSeveralCodesInOneCell(): void
    {
        $reader = new BarcodeCellReader();

        foreach (['4005556147090, 4005555001997', '4005556147090;4005555001997', '4005556147090 / 4005555001997', "4005556147090\n4005555001997", '4005556147090 4005555001997'] as $text) {
            $read = $reader->read(CellInput::text($text));

            self::assertSame(BarcodeCertainty::Barcodes, $read->certainty, $text);
            self::assertSame(['4005556147090', '4005555001997'], $read->codes, $text);
            self::assertSame([], $read->other, $text);
            self::assertSame('4005556147090, 4005555001997', $read->eanList()->toStored());
        }
    }

    public function testABarcodeAndSomethingElseAreKeptApart(): void
    {
        $read = (new BarcodeCellReader())->read(CellInput::text('4005556169320, 12345, X002ROECA7'));

        self::assertSame(BarcodeCertainty::Barcodes, $read->certainty);
        self::assertSame([self::EAN], $read->codes);
        self::assertSame(['X002ROECA7', '12345'], $read->other, 'Never compared with the catalogue as a barcode');
    }

    #[DataProvider('damaged')]
    public function testABarcodeASpreadsheetSpoiledIsReportedNotRepaired(CellInput $cell, BarcodeDamage $damage, string $typed): void
    {
        $read = (new BarcodeCellReader())->read($cell);

        self::assertSame(BarcodeCertainty::Damaged, $read->certainty);
        self::assertSame($damage, $read->damage);
        self::assertSame([], $read->codes, 'The lost digits cannot be brought back');
        self::assertSame([$typed], $read->other);
        self::assertTrue($read->eanList()->isEmpty());
    }

    /**
     * @return iterable<string, array{CellInput, BarcodeDamage, string}>
     */
    public static function damaged(): iterable
    {
        yield 'the README\'s 4.00556E+12' => [CellInput::text('4.00556E+12'), BarcodeDamage::ScientificNotation, '4.00556E+12'];
        yield 'lower case, no plus' => [CellInput::text('4.00556e12'), BarcodeDamage::ScientificNotation, '4.00556e12'];
        yield 'a decimal comma' => [CellInput::text('4,00556E+12'), BarcodeDamage::ScientificNotation, '4,00556E+12'];
        yield 'two decimals' => [CellInput::text('4.01E+12'), BarcodeDamage::ScientificNotation, '4.01E+12'];
        yield 'a UPC-A' => [CellInput::text('3.60003E+10'), BarcodeDamage::ScientificNotation, '3.60003E+10'];
        yield 'an EAN-8' => [CellInput::text('9.63851E+07'), BarcodeDamage::ScientificNotation, '9.63851E+07'];
        yield 'saved and opened again: a number that lost its end' => [CellInput::number(4005560000000), BarcodeDamage::DigitsLost, '4005560000000'];
        yield 'the same as a float' => [CellInput::number(4.00556E+12), BarcodeDamage::DigitsLost, '4005560000000'];
        yield 'the same as text' => [CellInput::text('4005560000000'), BarcodeDamage::DigitsLost, '4005560000000'];
        yield 'the same with decimals' => [CellInput::text('4005560000000.0'), BarcodeDamage::DigitsLost, '4005560000000'];
        yield 'a UPC-A that lost its end' => [CellInput::number(360003000000), BarcodeDamage::DigitsLost, '360003000000'];
    }

    /**
     * @param list<string> $other
     */
    #[DataProvider('notBarcodes')]
    public function testWhatIsNoBarcodeComesBackAsTyped(CellInput $cell, array $other): void
    {
        $read = (new BarcodeCellReader())->read($cell);

        self::assertSame(BarcodeCertainty::NotABarcode, $read->certainty);
        self::assertSame([], $read->codes);
        self::assertSame($other, $read->other);
        self::assertNull($read->damage);
    }

    /**
     * @return iterable<string, array{CellInput, list<string>}>
     */
    public static function notBarcodes(): iterable
    {
        yield 'n/a' => [CellInput::text('n/a'), ['n/a']];
        yield 'a dash' => [CellInput::text('-'), ['-']];
        yield 'a question mark' => [CellInput::text('?'), ['?']];
        yield 'a word' => [CellInput::text('none'), ['none']];
        yield 'a wrong check digit' => [CellInput::text('4005556169321'), ['4005556169321']];
        yield 'a wrong check digit as a number' => [CellInput::number(4005556169321), ['4005556169321']];
        yield 'a catalogue number' => [CellInput::text('12345'), ['12345']];
        yield 'a catalogue number with a dash' => [CellInput::text('6000-5533'), ['6000-5533']];
        yield 'a shop\'s own code with letters' => [CellInput::text('X002ROECA7'), ['X002ROECA7']];
        yield 'ten digits' => [CellInput::text('7934603125'), ['7934603125']];
        yield 'a number that only ends in a few zeros' => [CellInput::number(4005556169000), ['4005556169000']];
        yield 'a short number that ends in zeros' => [CellInput::number(1200000), ['1200000']];
        yield 'a small number in scientific notation' => [CellInput::text('1e5'), ['1e5']];
        yield 'zero' => [CellInput::number(0), ['0']];
        yield 'a negative number' => [CellInput::number(-4005556169320), ['-4005556169320']];
        yield 'a decimal' => [CellInput::number(4005556169320.5), ['4005556169320.5']];
        yield 'yes' => [CellInput::boolean(true), ['TRUE']];
        yield 'a date' => [CellInput::date(TestClock::now()), ['2026-10-14']];
        yield 'a duration' => [CellInput::duration(4157), ['4157']];
        yield 'an emoji' => [CellInput::text('🧩'), ['🧩']];
    }

    public function testNumbersNoBarcodeFitsIn(): void
    {
        $reader = new BarcodeCellReader();

        foreach ([NAN, INF, -INF, 1e15, 1e30] as $number) {
            $read = $reader->read(CellInput::number($number));

            self::assertSame(BarcodeCertainty::NotABarcode, $read->certainty);
            self::assertSame([], $read->codes);
        }
    }

    public function testAnAndroidMisreadOfARavensburgerCodeIsNoBarcode(): void
    {
        // 4005556… read without its two zeros keeps a valid check digit (EanList::ravensburgerMisreadSuggestion)
        $read = (new BarcodeCellReader())->read(CellInput::text('45556169320'));

        self::assertSame(BarcodeCertainty::NotABarcode, $read->certainty);
        self::assertSame(['45556169320'], $read->other);
    }

    public function testAnEmptyCellIsNoCode(): void
    {
        foreach ([CellInput::blank(), CellInput::text(''), CellInput::text('   '), CellInput::text("\u{00A0}\u{200B}")] as $cell) {
            $read = (new BarcodeCellReader())->read($cell);

            self::assertSame(BarcodeCertainty::NoCode, $read->certainty);
            self::assertSame([], $read->codes);
            self::assertSame([], $read->other);
        }
    }

    public function testAVeryLongTextIsCut(): void
    {
        $read = (new BarcodeCellReader())->read(CellInput::text(str_repeat('4005556169320, ', 10_000)));

        self::assertSame(BarcodeCertainty::NotABarcode, $read->certainty);
        self::assertCount(1, $read->other);
        self::assertSame(200, mb_strlen($read->other[0]));
    }
}
