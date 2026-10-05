<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Value;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use SpeedPuzzling\Web\Value\BrandCodeCheckDigit;

final class BrandCodeCheckDigitTest extends TestCase
{
    /**
     * @param list<string> $expected
     */
    #[DataProvider('cases')]
    public function testAliases(null|string $eans, null|string $brandCodes, array $expected): void
    {
        self::assertSame($expected, BrandCodeCheckDigit::aliases($eans, $brandCodes));
    }

    /**
     * @return iterable<string, array{null|string, null|string, list<string>}>
     */
    public static function cases(): iterable
    {
        // Ravensburger prints "12 002 028 8" next to the barcode 4005555020288
        yield 'new format stored without the digit' => ['4005555020288', '12002028', ['120020288']];
        yield 'new format stored with the digit' => ['4005555020288', '120020288', ['12002028']];
        yield 'new format typed with spaces and a dash' => ['4005555020288', '12 002 028-8', ['12002028']];
        yield 'old format stored without the digit' => ['4005556169078', '16907', ['169078']];
        yield 'old format stored with the digit' => ['4005556169078', '169078', ['16907']];
        yield 'another brand building its EAN the same way (Trefl)' => ['5900511374414', '37441', ['374414']];
        yield 'several EANs and codes, each code with its own EAN' => [
            '4005556174812, 4005556197484',
            '17481, 19748',
            ['174812', '197484'],
        ];
        yield 'both forms stored - nothing to add' => ['4005555020288', '12002028, 120020288', []];
        yield 'a UPC-A is a 13-digit barcode with a leading zero' => ['036000291452', '29145', ['291452']];

        yield 'no EAN - nothing to check the digit against' => [null, '12002028', []];
        yield 'no brand code' => ['4005555020288', null, []];
        yield 'an EAN with a wrong check digit is no barcode' => ['4005555020287', '12002028', []];
        yield 'a catalogue number in the EAN field has no check digit' => ['6000-5468', '60005', []];
        yield 'a code the EAN does not end with' => ['4005555020288', '12002029', []];
        yield 'a last digit that is not the check digit' => ['4005555020288', '120020281', []];
        yield 'a code with letters' => ['4005555020288', 'RB02028', []];
        yield 'a code shorter than 5 digits' => ['4005556000289', '0028', []];
        yield 'a 5-digit code ending in the check digit gives no 4-digit alias' => ['4005556169122', '16912', ['169122']];
        yield 'a barcode typed into the code field (11+ digits)' => ['4005556147090', '4005556147090', []];
    }
}
