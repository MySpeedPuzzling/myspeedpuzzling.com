<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Value;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use SpeedPuzzling\Web\Value\EanList;

final class EanListTest extends TestCase
{
    #[DataProvider('validLists')]
    public function testValidListsPass(string $input): void
    {
        self::assertSame([], EanList::invalidCodes($input, null));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function validLists(): iterable
    {
        yield 'EAN-13' => ['4005555011897'];
        yield 'several codes, spaces around commas' => ['4005556164462 ,  4005555002130'];
        yield 'trailing comma' => ['4005555011897,'];
        yield 'empty field' => [''];
        yield 'UPC-A' => ['036000291452'];
        yield 'UPC-A without its leading zero, as the catalogue stores it' => ['36000291452'];
        yield 'GTIN-14 with a zero indicator' => ['04005555011897'];
        yield 'digits grouped as printed under the barcode' => ['4 005555 011897'];
    }

    #[DataProvider('invalidCodes')]
    public function testInvalidCodesAreReported(string $input, string $reportedCode): void
    {
        self::assertSame([['code' => $reportedCode, 'suggestion' => null]], EanList::invalidCodes($input, null));
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function invalidCodes(): iterable
    {
        yield 'wrong check digit' => ['4005555011898', '4005555011898'];
        yield 'article number in the EAN field' => ['12001189', '12001189'];
        yield 'ten digits' => ['7934603125', '7934603125'];
        yield 'fourteen digits without a zero indicator' => ['40055555013631', '40055555013631'];
        yield 'letters' => ['X001FPLBM3', 'X001FPLBM3'];
        yield 'another separator than a comma' => ['4005556133710 & 4005555010432', '4005556133710 & 4005555010432'];
        yield 'only the bad one of a list' => ['4005555011897, 4005555011898', '4005555011898'];
    }

    public function testRavensburgerCodeWithoutItsTwoZerosGetsTheFullCodeSuggested(): void
    {
        // Passes the check digit (dropping two zeros keeps it valid), so it needs its own rule
        self::assertSame(
            [['code' => '45555011897', 'suggestion' => '4005555011897']],
            EanList::invalidCodes('4005555011897, 45555011897', '4005555011897'),
        );

        self::assertSame(
            [['code' => '045555017417', 'suggestion' => '4005555017417']],
            EanList::invalidCodes('045555017417', null),
        );

        self::assertSame(
            [['code' => '045556130443', 'suggestion' => '4005556130443']],
            EanList::invalidCodes('045556130443', null),
            'the older 4005556 range too',
        );
    }

    public function testCodesThePuzzleAlreadyCarriesAreLeftAlone(): void
    {
        $current = '17399, 45555011903, 4005555011903';

        self::assertSame([], EanList::invalidCodes($current, $current));
        self::assertSame([], EanList::invalidCodes('4005555011903,17399', $current), 'order and spacing do not matter');
        self::assertSame([], EanList::invalidCodes('045555011903', $current), 'leading zeros do not matter');

        self::assertSame(
            [['code' => '17400', 'suggestion' => null]],
            EanList::invalidCodes('17399, 17400', $current),
            'a new invalid code next to an old one is still reported',
        );
    }

    public function testGtinsAreTheValidBarcodesPaddedBack(): void
    {
        self::assertSame(
            ['gtin8' => ['96385074'], 'gtin13' => ['4005556175895', '0036000291452', '0012345678905']],
            // EAN-13, a UPC-A stored without its leading zero, a UPC-A stored without two, an EAN-8, a wrong check
            // digit, a brand code, a catalogue number, the same code twice
            EanList::fromStored('4005556175895, 36000291452, 12345678905, 96385074, 4005556147091, RB-123, 14709, 004005556175895')->gtins(),
        );
    }

    public function testNoGtinsWithoutAValidBarcode(): void
    {
        self::assertSame(['gtin8' => [], 'gtin13' => []], EanList::fromStored(null)->gtins());
        self::assertSame(['gtin8' => [], 'gtin13' => []], EanList::fromStored('None, 4005556147091, 1005290289')->gtins());
        // A valid EAN-8 of the restricted circulation range (starts with 0): a shop's own code, never a GTIN
        self::assertSame(['gtin8' => [], 'gtin13' => []], EanList::fromStored('01234565')->gtins());
    }

    /**
     * @param list<string> $codes
     * @param list<string> $junk
     * @param list<string> $display
     */
    #[DataProvider('storedValues')]
    public function testStoredValueIsReadAsTheList(null|string $stored, array $codes, array $junk, null|string $toStored, array $display, bool $formatOnly): void
    {
        $list = EanList::fromStored($stored);

        self::assertSame($codes, $list->codes());
        self::assertSame($junk, $list->junk());
        self::assertSame($toStored, $list->toStored());
        self::assertSame($display, $list->display());
        self::assertSame($formatOnly, $list->isFormatOnlyChangeOf($stored));
        self::assertTrue(EanList::fromStored($list->toStored())->equals($list), 'the stored form reads as the same list');
        self::assertTrue(EanList::fromInputs($list->display())->equals($list), 'the inputs a form shows read as the same list');
    }

    /**
     * @return iterable<string, array{null|string, list<string>, list<string>, null|string, list<string>, bool}>
     */
    public static function storedValues(): iterable
    {
        yield 'nothing' => [null, [], [], null, [], true];
        yield 'canonical already' => ['4005556147090, 4005555001997', ['4005556147090', '4005555001997'], [], '4005556147090, 4005555001997', ['4005556147090', '4005555001997'], true];
        yield 'two barcodes separated by a space only - split, no format change' => ['4005556147090 4005555001997', ['4005556147090', '4005555001997'], [], '4005556147090, 4005555001997', ['4005556147090', '4005555001997'], false];
        yield 'dashes in an EAN-13 with a right check digit' => ['400-5556-147090', ['4005556147090'], [], '4005556147090', ['4005556147090'], true];
        yield 'EAN-13 of a UPC-A with its leading zeros' => ['0091683108909', ['91683108909'], [], '91683108909', ['091683108909'], true];
        yield 'a UPC-A shown with its 12th digit' => ['91683108909', ['91683108909'], [], '91683108909', ['091683108909'], true];
        yield 'an EAN-8 with its leading zero comes back as printed' => ['01234565', ['1234565'], [], '1234565', ['01234565'], true];
        yield 'the mark printed after the digits under a barcode' => ['5012269036039' . chr(62), ['5012269036039'], [], '5012269036039', ['5012269036039'], true];
        yield 'an Amazon code is no number' => ['X002ROECA7', [], ['X002ROECA7'], 'X002ROECA7', ['X002ROECA7'], true];
        yield 'a placeholder word is no number' => ['None', [], ['None'], 'None', ['None'], true];
        yield 'N/A stays one' => ['N/A', [], ['N/A'], 'N/A', ['N/A'], true];
        yield 'a lone dash is nothing' => ['-', [], [], null, [], true];
        yield 'spaces around short numbers' => [' 14709 , 12000199 ', ['14709', '12000199'], [], '14709, 12000199', ['14709', '12000199'], true];
        yield 'a brand code in the EAN field' => ['rb 14709', [], ['rb 14709'], 'rb 14709', ['rb 14709'], true];
        yield 'full-width digits' => ['４００５５５６１４７０９０', ['4005556147090'], [], '4005556147090', ['4005556147090'], true];
        yield 'junk keeps its place' => ['None, 0021081241953', ['21081241953'], ['None'], 'None, 21081241953', ['None', '021081241953'], true];
        yield 'a catalogue number stays as typed' => ['6000-5468', ['6000-5468'], [], '6000-5468', ['6000-5468'], true];
        yield 'a # before a number stays' => ['#6255', ['#6255'], [], '#6255', ['#6255'], true];
        yield 'numbers that are no barcodes are never split' => ['4795/4', ['4795/4'], [], '4795/4', ['4795/4'], true];
        yield 'spaces inside a number that is no barcode stay' => ['12 556 2', ['12 556 2'], [], '12 556 2', ['12 556 2'], true];
        yield 'leading zeros of a number that is no barcode stay' => ['04512', ['04512'], [], '04512', ['04512'], true];
        yield 'a barcode length with a wrong check digit stays as typed' => ['5 051237 060134', ['5 051237 060134'], [], '5 051237 060134', ['5 051237 060134'], true];
        yield 'a barcode that could not be shown as printed without its zeros stays' => ['007346037677', ['007346037677'], [], '007346037677', ['007346037677'], true];
        yield 'an ISBN printed with its dash' => ['978-0593137642', ['9780593137642'], [], '9780593137642', ['9780593137642'], true];
        yield 'the same code twice' => ['4005556147090, 04005556147090', ['4005556147090'], [], '4005556147090', ['4005556147090'], true];
        yield 'an 11-digit number failing every check digit stays as stored' => ['12345678901', ['12345678901'], [], '12345678901', ['12345678901'], true];
        yield 'a 10-digit number is never padded' => ['7934603125', ['7934603125'], [], '7934603125', ['7934603125'], true];
        yield 'an 8-digit catalogue number with a dash is no EAN-8, whatever its check digit' => ['6000-5533', ['6000-5533'], [], '6000-5533', ['6000-5533'], true];
        yield 'an EAN-8 printed with a space is one' => ['9638 5074', ['96385074'], [], '96385074', ['96385074'], true];
        yield 'a stray left-to-right mark goes' => ["\u{200E}4005556147090", ['4005556147090'], [], '4005556147090', ['4005556147090'], true];
        yield 'a numero sign stays as typed' => ['№ 14114', ['№ 14114'], [], '№ 14114', ['№ 14114'], true];
        yield 'full-width junk stays verbatim' => ['ＸＹＺ', [], ['ＸＹＺ'], 'ＸＹＺ', ['ＸＹＺ'], true];
        yield 'a comma between digit groups is no format change' => ['15,427', ['15', '427'], [], '15, 427', ['15', '427'], false];
        yield 'a comma between two barcodes is the separator' => ['4005556147090,4005555001997', ['4005556147090', '4005555001997'], [], '4005556147090, 4005555001997', ['4005556147090', '4005555001997'], true];
    }

    public function testInputsAreOneCodeEachAndBlankOnesAreDropped(): void
    {
        $list = EanList::fromInputs(['4005556147090', '', null, ' 0 4005555 001997 ', '4005556147090, 4005556202027']);

        self::assertSame(['4005556147090', '4005555001997', '4005556202027'], $list->codes());
        self::assertSame('4005556147090, 4005555001997, 4005556202027', $list->toStored());
        self::assertNull(EanList::fromInputs(['', '  ', null])->toStored());
    }

    public function testUnionKeepsTheFirstListsCodesFirstAndEveryCodeOnce(): void
    {
        $survivor = EanList::fromStored('4005556147090, None');
        $merged = EanList::fromStored('04005555001997, 4005556147090, NONE');

        // Junk is kept verbatim - "None" and "NONE" are two values
        self::assertSame('4005556147090, None, 4005555001997, NONE', $survivor->union($merged)->toStored());
        self::assertSame('4005555001997, 4005556147090, NONE, None', $merged->union($survivor)->toStored());
    }

    public function testTheSameCodesInTheSameOrderAreEqualWhateverTheirStoredForm(): void
    {
        self::assertTrue(EanList::fromStored('0091683108909, None')->equals(EanList::fromInputs(['091683108909', ' None '])));
        self::assertFalse(EanList::fromStored('None')->equals(EanList::fromStored('none')), 'junk is kept verbatim');
        self::assertFalse(EanList::fromStored('4005556147090, 4005555001997')->equals(EanList::fromStored('4005555001997, 4005556147090')));
        self::assertFalse(EanList::fromStored('6000-5468')->equals(EanList::fromStored('60005468')), 'a number kept as typed is its text');
    }

    public function testCodesAreComparedAsTheFormShowsThem(): void
    {
        self::assertSame([], EanList::invalidCodes('#6255', '#6255'), 'a stored code comes back as the form showed it');
        self::assertSame([], EanList::invalidCodes('021081241953', '0021081241953'));
        self::assertSame([], EanList::invalidCodes('01234565', '1234565'));
        self::assertSame([], EanList::invalidCodes('N/A', 'N/A'));
        self::assertSame([['code' => '6255', 'suggestion' => null]], EanList::invalidCodes('6255', '#6255'));
    }

    public function testACatalogueNumberIsNoGtinWhateverItsCheckDigit(): void
    {
        self::assertSame(['gtin8' => [], 'gtin13' => []], EanList::fromStored('6000-5533')->gtins());
        self::assertSame(['gtin8' => ['96385074'], 'gtin13' => []], EanList::fromStored('9638 5074')->gtins());
        self::assertFalse(EanList::isBarcodeAsTyped('6000-5533'));
        self::assertTrue(EanList::isBarcodeAsTyped('978-0593137642'), 'marks inside 12 digits and more are print');
    }

    public function testACommaBetweenDigitsNextToANumberThatIsNoBarcodeIsAmbiguous(): void
    {
        self::assertTrue(EanList::hasAmbiguousComma('15,427'));
        self::assertTrue(EanList::hasAmbiguousComma('4005556147090,427'));
        self::assertFalse(EanList::hasAmbiguousComma('4005556147090,4005555001997'));
        self::assertFalse(EanList::hasAmbiguousComma('15, 427'));
    }

    public function testBarcodesAreGtinsThatCanBeShownAsPrinted(): void
    {
        self::assertTrue(EanList::isBarcode('4005556147090'));
        self::assertTrue(EanList::isBarcode('01234565'), 'an EAN-8');
        self::assertTrue(EanList::isBarcode('036000291452'), 'a UPC-A');
        self::assertTrue(EanList::isBarcode('36000291452'), 'a UPC-A without its leading zero');
        self::assertTrue(EanList::isBarcode('04005556147090'), 'a GTIN-14');
        self::assertFalse(EanList::isBarcode('5051237060134'), 'a wrong check digit');
        self::assertFalse(EanList::isBarcode('125562'), 'no GTIN length');
        self::assertFalse(EanList::isBarcode('007346037677'), 'its 10 digits without the zeros could not be shown as printed');
        self::assertSame('4005556195145', EanList::ravensburgerTruncationSuggestion('005556195145'));
        self::assertNull(EanList::ravensburgerTruncationSuggestion('005556195146'));
    }
}
