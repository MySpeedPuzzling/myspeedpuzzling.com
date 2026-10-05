<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Value;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use SpeedPuzzling\Web\Value\BrandCodeList;
use SpeedPuzzling\Web\Value\EanList;
use SpeedPuzzling\Web\Value\PuzzleCodesCleanup;
use SpeedPuzzling\Web\Value\PuzzleCodesCleanupReason;
use SpeedPuzzling\Web\Value\PuzzleCodesReportRow;
use SpeedPuzzling\Web\Value\PuzzleSearchKeys;

final class PuzzleCodesCleanupTest extends TestCase
{
    #[DataProvider('formatOnly')]
    public function testAFormatOnlyChangeIsWrittenWithoutAReportRow(null|string $ean, null|string $brandCodes, bool $writeEans, bool $writeBrandCodes): void
    {
        $cleanup = PuzzleCodesCleanup::of('puzzle-id', 'Puzzle', $ean, $brandCodes);

        self::assertSame($writeEans, $cleanup->writeEans);
        self::assertSame($writeBrandCodes, $cleanup->writeBrandCodes);
        self::assertSame([], $cleanup->reportRows);
    }

    /**
     * @return iterable<string, array{null|string, null|string, bool, bool}>
     */
    public static function formatOnly(): iterable
    {
        yield 'leading zeros of a UPC-A' => ['0091683108909', null, true, false];
        yield 'leading zero of an EAN-8 (shown as printed again)' => ['01234565', null, true, false];
        yield 'a GTIN-14 of an EAN-13' => ['04005556147090', null, true, false];
        yield 'spaces of a barcode as printed under it' => ['4 005556 147090', '14709', true, false];
        yield 'the separator' => ['4005556147090,4005555001997', null, true, false];
        yield 'dashes in an EAN-13' => ['400-5556-147090', null, true, false];
        yield 'full-width digits' => ['４００５５５６１４７０９０', null, true, false];
        yield 'a placeholder dash' => ['-', null, true, false];
        yield 'brand codes in upper case, each once' => [null, ' 05-122s ,  05-122S, 6500-5354', false, true];
        yield 'a stray left-to-right mark before a brand code' => [null, "\u{200E}3723-2", false, true];
    }

    #[DataProvider('neverWritten')]
    public function testANumberThatIsNoBarcodeIsNeverChanged(string $ean): void
    {
        $cleanup = PuzzleCodesCleanup::of('puzzle-id', 'Puzzle', $ean, null);

        self::assertFalse($cleanup->writeEans);
        self::assertSame($ean, EanList::fromStored($ean)->toStored(), 'nothing a writer stores changes it');
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function neverWritten(): iterable
    {
        yield 'spaces inside a catalogue number' => ['12 556 2'];
        yield 'spaces and a leading zero' => ['09 359 5'];
        yield 'a barcode length with a wrong check digit' => ['5 051237 060134'];
        yield 'leading zero of a short number' => ['04512'];
        yield 'leading zero of a 4-digit number' => ['0162'];
        yield 'Ravensburger without its first digit' => ['005556195145'];
        yield 'a barcode whose zeros could not be shown again' => ['007346037677'];
        yield 'a catalogue number with a dash' => ['6000-5468'];
        yield 'an 8-digit catalogue number whose check digit fits' => ['6000-5533'];
    }

    public function testCanonicalCodesChangeNothing(): void
    {
        $cleanup = PuzzleCodesCleanup::of('puzzle-id', 'Puzzle', '4005556147090, 4005555001997', '14709, 12000-199');

        self::assertFalse($cleanup->writeEans);
        self::assertFalse($cleanup->writeBrandCodes);
        self::assertSame([], $cleanup->reportRows);
    }

    /**
     * @param list<array{string, PuzzleCodesCleanupReason, null|string, string, null|string}> $rows field, reason,
     *        proposed (the field), detail, proposed brand codes
     */
    #[DataProvider('reported')]
    public function testWhatAPersonDecidesIsReportedWithOneProposal(null|string $ean, null|string $brandCodes, bool $writeEans, bool $writeBrandCodes, array $rows): void
    {
        $cleanup = PuzzleCodesCleanup::of('puzzle-id', 'Puzzle', $ean, $brandCodes);

        self::assertSame($writeEans, $cleanup->writeEans);
        self::assertSame($writeBrandCodes, $cleanup->writeBrandCodes);
        self::assertSame($rows, array_map(
            static fn (PuzzleCodesReportRow $row): array => [$row->field, $row->reason, $row->proposed, $row->detail, $row->proposedBrandCodes],
            $cleanup->reportRows,
        ));
    }

    /**
     * @return iterable<string, array{null|string, null|string, bool, bool, list<array{string, PuzzleCodesCleanupReason, null|string, string, null|string}>}>
     */
    public static function reported(): iterable
    {
        yield 'a code with letters moves into the brand codes' => ['PZL6522', null, false, false, [
            ['ean', PuzzleCodesCleanupReason::EanNotANumber, null, 'PZL6522', 'PZL6522'],
        ]];
        yield 'a placeholder word is dropped, not moved' => ['None', null, false, false, [
            ['ean', PuzzleCodesCleanupReason::EanNotANumber, null, 'None', null],
        ]];
        yield 'a code already among the brand codes is not added twice' => ['39610AL, 4005556147090', '39610al', false, true, [
            ['ean', PuzzleCodesCleanupReason::EanNotANumber, '4005556147090', '39610AL', '39610AL'],
        ]];
        yield 'the barcode is written, the code with letters reported' => ['0021081241953, X002ROECA7', '17481', true, false, [
            ['ean', PuzzleCodesCleanupReason::EanNotANumber, '21081241953', 'X002ROECA7', '17481, X002ROECA7'],
        ]];
        yield 'two barcodes in one part are split' => ['4005556147090 4005555001997', null, false, false, [
            ['ean', PuzzleCodesCleanupReason::EanSeveralNumbersInOne, '4005556147090, 4005555001997', '4005556147090 4005555001997', null],
        ]];
        yield 'numbers that are no barcodes are one catalogue number - one row' => ['4795/4', null, false, false, [
            ['ean', PuzzleCodesCleanupReason::EanCatalogueNumber, null, '4795/4', '4795/4'],
        ]];
        yield 'a catalogue number moves, the existing brand codes stay first' => ['4005556147090, 6000-5753', '14709', false, false, [
            ['ean', PuzzleCodesCleanupReason::EanCatalogueNumber, '4005556147090', '6000-5753', '14709, 6000-5753'],
        ]];
        yield 'spaces inside a number that is no barcode' => ['12 556 2', null, false, false, [
            ['ean', PuzzleCodesCleanupReason::EanNotABarcode, null, '12 556 2', '12 556 2'],
        ]];
        yield 'a barcode length with a wrong check digit stays for a person' => ['5 051237 060134', null, false, false, [
            ['ean', PuzzleCodesCleanupReason::EanNotABarcode, '5 051237 060134', '5 051237 060134 (check digit)', null],
        ]];
        yield 'Ravensburger without its first digit gets it back' => ['005556195145', null, false, false, [
            ['ean', PuzzleCodesCleanupReason::EanNotABarcode, '4005556195145', '005556195145 → 4005556195145', null],
        ]];
        yield 'the Ravensburger misread next to the right code' => ['4005555011897, 45555011897', null, false, false, [
            ['ean', PuzzleCodesCleanupReason::EanRavensburgerMisread, '4005555011897', '45555011897 → 4005555011897', null],
        ]];
        yield 'a word in the brand code field stays as typed' => [null, 'Clementoni, 39612', false, false, [
            ['identification_number', PuzzleCodesCleanupReason::BrandCodeNotACode, '39612', 'Clementoni', '39612'],
        ]];
        yield 'words and a placeholder' => [null, 'Alpine village, N/A, PZFSLF', false, false, [
            ['identification_number', PuzzleCodesCleanupReason::BrandCodeNotACode, 'PZFSLF', 'Alpine village | N/A', 'PZFSLF'],
        ]];
        yield 'a code of capital letters only is a code' => [null, 'PZFSLF, PZL/USA', false, false, []];
        yield 'an 8-digit catalogue number with a dash moves, whatever its check digit' => ['6000-5533', null, false, false, [
            ['ean', PuzzleCodesCleanupReason::EanCatalogueNumber, null, '6000-5533', '6000-5533'],
        ]];
        yield 'a comma between digits leaves the EAN field to a person' => ['15,427', null, false, false, [
            ['ean', PuzzleCodesCleanupReason::CommaBetweenDigits, '15,427', '15,427', null],
        ]];
        yield 'a comma between digits leaves the brand codes to a person' => ['PZL6522', '482,239', false, false, [
            ['ean', PuzzleCodesCleanupReason::EanNotANumber, null, 'PZL6522', '482,239, PZL6522'],
            ['identification_number', PuzzleCodesCleanupReason::CommaBetweenDigits, '482,239, PZL6522', '482,239', '482,239, PZL6522'],
        ]];
        yield 'prose with a number is kept for a person, never written' => [null, 'Article 30226, 68-08 lot number 23.10.18, UPC is 0045622965214', false, false, [
            ['identification_number', PuzzleCodesCleanupReason::BrandCodeProse, 'ARTICLE 30226, 68-08 LOT NUMBER 23.10.18, UPC IS 0045622965214', 'Article 30226 | 68-08 lot number 23.10.18 | UPC is 0045622965214', 'ARTICLE 30226, 68-08 LOT NUMBER 23.10.18, UPC IS 0045622965214'],
        ]];
        yield 'codes typed in lower case are no prose' => [null, 'jk009, 1183pz, m051524b', false, true, []];
    }

    public function testAWrittenFieldKeepsTheSearchKey(): void
    {
        foreach ([['0091683108909, 4 005556 147090', ' rb-500-001 ,RB-500-001'], ['01234565, -', '05-122s']] as [$ean, $brandCodes]) {
            $cleanup = PuzzleCodesCleanup::of('puzzle-id', 'Puzzle', $ean, $brandCodes);

            self::assertTrue($cleanup->writeEans && $cleanup->writeBrandCodes);
            self::assertSame(
                PuzzleSearchKeys::codes($ean, $brandCodes),
                PuzzleSearchKeys::codes(EanList::fromStored($ean)->toStored(), BrandCodeList::fromStored($brandCodes)->toStored()),
            );
        }
    }

    public function testTheReportRowIsSafeInASpreadsheet(): void
    {
        $row = PuzzleCodesCleanup::of('018d0003-0000-0000-0000-000000000001', '=HYPERLINK("x")', '-4512', '@code')->reportRows[0];

        self::assertSame(
            ['018d0003-0000-0000-0000-000000000001', '\'=HYPERLINK("x")', 'ean', '\'-4512', '', 'ean_catalogue_number', '\'-4512', '\'@code', '\'@CODE, -4512'],
            $row->toCsvRow(),
        );
    }
}
