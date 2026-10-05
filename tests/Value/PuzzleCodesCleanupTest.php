<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Value;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use SpeedPuzzling\Web\Value\PuzzleCodesCleanup;
use SpeedPuzzling\Web\Value\PuzzleCodesCleanupReason;
use SpeedPuzzling\Web\Value\PuzzleCodesReportRow;

final class PuzzleCodesCleanupTest extends TestCase
{
    #[DataProvider('formatOnly')]
    public function testAFormatOnlyChangeIsWrittenWithoutAReportRow(null|string $ean, null|string $brandCodes, null|string $storedEan, null|string $storedBrandCodes): void
    {
        $cleanup = PuzzleCodesCleanup::of('puzzle-id', 'Puzzle', $ean, $brandCodes);

        self::assertTrue($cleanup->writable);
        self::assertSame([], $cleanup->reportRows);
        self::assertSame($storedEan, $cleanup->eans->toStored());
        self::assertSame($storedBrandCodes, $cleanup->brandCodes->toStored());
    }

    /**
     * @return iterable<string, array{null|string, null|string, null|string, null|string}>
     */
    public static function formatOnly(): iterable
    {
        yield 'leading zeros of a UPC-A' => ['0091683108909', null, '91683108909', null];
        yield 'spaces as printed under the barcode' => ['4 005556 157891', '14709', '4005556157891', '14709'];
        yield 'the separator' => ['4005556147090,4005555001997', null, '4005556147090, 4005555001997', null];
        yield 'dashes in an EAN-13' => ['400-5556-147090', null, '4005556147090', null];
        yield 'full-width digits' => ['４００５５５６１４７０９０', null, '4005556147090', null];
        yield 'a placeholder dash' => ['-', null, null, null];
        yield 'brand codes in upper case, each once' => [null, ' 05-122s ,  05-122S, 6500-5354', null, '05-122S, 6500-5354'];
    }

    public function testCanonicalCodesChangeNothing(): void
    {
        $cleanup = PuzzleCodesCleanup::of('puzzle-id', 'Puzzle', '4005556147090, 4005555001997', '14709, 12000-199');

        self::assertFalse($cleanup->eanChanges);
        self::assertFalse($cleanup->brandCodesChange);
        self::assertFalse($cleanup->writable);
        self::assertSame([], $cleanup->reportRows);
    }

    /**
     * @param list<array{PuzzleCodesCleanupReason, null|string, string}> $rows
     */
    #[DataProvider('reported')]
    public function testWhatAPersonDecidesIsReportedAndNotWritten(null|string $ean, null|string $brandCodes, bool $writable, array $rows): void
    {
        $cleanup = PuzzleCodesCleanup::of('puzzle-id', 'Puzzle', $ean, $brandCodes);

        self::assertSame($writable, $cleanup->writable);
        self::assertSame($rows, array_map(
            static fn (PuzzleCodesReportRow $row): array => [$row->reason, $row->proposed, $row->detail],
            $cleanup->reportRows,
        ));
    }

    /**
     * @return iterable<string, array{null|string, null|string, bool, list<array{PuzzleCodesCleanupReason, null|string, string}>}>
     */
    public static function reported(): iterable
    {
        yield 'junk only - nothing to write' => ['X002ROECA7', null, false, [
            [PuzzleCodesCleanupReason::EanNotANumber, null, 'X002ROECA7'],
        ]];
        yield 'junk after a code with leading zeros - the code is written, the junk reported' => ['0021081241953, None', null, true, [
            [PuzzleCodesCleanupReason::EanNotANumber, '21081241953', 'None'],
        ]];
        yield 'junk before a code would move' => ['None, 4005556147090', null, false, [
            [PuzzleCodesCleanupReason::EanNotANumber, '4005556147090', 'None'],
        ]];
        yield 'two codes in one part' => ['4005556147090 4005555001997', null, false, [
            [PuzzleCodesCleanupReason::EanSeveralNumbersInOne, '4005556147090, 4005555001997', '4005556147090 4005555001997'],
        ]];
        yield 'a catalogue number in the EAN field' => ['4005556147090, 6000-5468', null, false, [
            [PuzzleCodesCleanupReason::EanCatalogueNumber, '4005556147090', '6000-5468'],
        ]];
        yield 'the Ravensburger misread next to the right code' => ['4005555011897, 45555011897', null, false, [
            [PuzzleCodesCleanupReason::EanRavensburgerMisread, '4005555011897', '45555011897 → 4005555011897'],
        ]];
        yield 'a word in the brand code field stays as typed' => [null, 'Clementoni, 39612', false, [
            [PuzzleCodesCleanupReason::BrandCodeNotACode, '39612', 'Clementoni'],
        ]];
        yield 'words and a placeholder' => [null, 'Alpine village, N/A, PZFSLF', false, [
            [PuzzleCodesCleanupReason::BrandCodeNotACode, 'PZFSLF', 'Alpine village | N/A'],
        ]];
        yield 'a code of capital letters only is a code' => [null, 'PZFSLF, PZL/USA', false, []];
    }

    public function testAnyFieldNotFormatOnlyLeavesThePuzzleAlone(): void
    {
        // The brand code alone would be a format-only change - but nothing of the puzzle is written
        $cleanup = PuzzleCodesCleanup::of('puzzle-id', 'Puzzle', '4005556147090 4005555001997', 'rb 14709');

        self::assertTrue($cleanup->brandCodesChange);
        self::assertFalse($cleanup->writable);
    }

    public function testTheReportRowCarriesThePuzzleAndTheStoredValue(): void
    {
        $row = PuzzleCodesCleanup::of('018d0003-0000-0000-0000-000000000001', 'Seashells', 'None', null)->reportRows[0];

        self::assertSame(
            ['018d0003-0000-0000-0000-000000000001', 'Seashells', 'ean', 'None', '', 'ean_not_a_number', 'None'],
            $row->toCsvRow(),
        );
    }
}
