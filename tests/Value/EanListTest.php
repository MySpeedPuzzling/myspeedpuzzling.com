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
            EanList::gtins('4005556175895, 36000291452, 12345678905, 96385074, 4005556147091, RB-123, 14709, 004005556175895'),
        );
    }

    public function testNoGtinsWithoutAValidBarcode(): void
    {
        self::assertSame(['gtin8' => [], 'gtin13' => []], EanList::gtins(null));
        self::assertSame(['gtin8' => [], 'gtin13' => []], EanList::gtins('None, 4005556147091, 1005290289'));
    }
}
