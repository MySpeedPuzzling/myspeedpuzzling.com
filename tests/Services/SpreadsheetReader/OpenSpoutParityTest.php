<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Services\SpreadsheetReader;

use OpenSpout\Common\Entity\Cell;
use OpenSpout\Common\Entity\Cell\EmptyCell;
use OpenSpout\Common\Entity\Cell\ErrorCell;
use OpenSpout\Common\Entity\Cell\FormulaCell;
use OpenSpout\Reader\XLSX\Options;
use OpenSpout\Reader\XLSX\Reader;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx as XlsxWriter;
use PHPUnit\Framework\TestCase;
use SpeedPuzzling\Web\Value\Spreadsheet\CellKind;
use SpeedPuzzling\Web\Value\Spreadsheet\ReadCell;

/**
 * The rows are read by a pass of our own, not by OpenSpout's row iterator (XlsxWorkbook says why). This holds the two
 * against each other on generated workbooks: the same rows, the same cells, the same values - wherever OpenSpout has
 * a value at all. What it has none for is ours alone and tested in XlsxReaderTest: a formula's saved error, a text
 * that starts with "=", the precision of a date.
 */
final class OpenSpoutParityTest extends TestCase
{
    use SpreadsheetFiles;

    private string $timezone = '';

    protected function setUp(): void
    {
        $this->folder = sys_get_temp_dir() . '/reader-test-' . bin2hex(random_bytes(6));
        mkdir($this->folder . '/tmp', 0700, true);
        // OpenSpout builds its dates in PHP's default time zone
        $this->timezone = date_default_timezone_get();
        date_default_timezone_set('UTC');
    }

    protected function tearDown(): void
    {
        date_default_timezone_set($this->timezone);
        $this->removeFiles();
    }

    public function testAGeneratedSheetOfEveryKindOfCell(): void
    {
        $path = $this->path();
        RawXlsx::generate($path, 400, 20);

        self::assertSame(8_000, $this->assertSameAsOpenSpout($path));
    }

    public function testAHandWrittenWorkbookWithGapsFormulasAndLooseReferences(): void
    {
        $sheetData = '<sheetData>'
            . '<row r="2"><c r="B2" t="s"><v>0</v></c><c r="C2" t="s"><v>1</v></c><c r="D2" t="s"><v>2</v></c><c r="F2" t="inlineStr"><is><r><t xml:space="preserve">in </t></r><r><t>place</t></r></is></c></row>'
            . '<row r="3"/>'
            . '<row r="4" s="1" customFormat="1"><c r="A4" s="1"/></row>'
            . '<row r="6"><c r="A6"><v>500</v></c><c r="B6"><v>3.25</v></c><c r="C6"><v>4005556123457</v></c><c r="D6"><v>-0.5</v></c><c r="E6"><v>1E-3</v></c></row>'
            . '<row r="7"><c r="A7" s="1"><v>45356</v></c><c r="B7" s="2"><v>45356</v></c><c r="C7" s="3"><v>1.0625</v></c><c r="D7" s="4"><v>0.5625</v></c><c r="E7" s="5"><v>45356.5625</v></c><c r="F7" s="6"><v>45356</v></c></row>'
            . '<row r="8"><c r="A8" t="b"><v>1</v></c><c r="B8" t="b"><v>0</v></c><c r="C8" t="e"><v>#N/A</v></c></row>'
            . '<row r="9"><c r="A9"><f>SUM(A6:B6)</f><v>503.25</v></c><c r="B9" t="str"><f>B2&amp;"!"</f><v>Sunset!</v></c><c r="C9" t="b"><f>A6&gt;1</f><v>1</v></c>'
            . '<c r="D9" s="1"><f>TODAY()</f><v>45357</v></c><c r="E9" s="3"><f>C7*2</f><v>2.125</v></c><c r="F9"><f>A1</f></c></row>'
            . '<row><c><v>10</v></c><c><v>11</v></c></row>'
            . '<row r="400"><c r="AX400" t="s"><v>3</v></c></row>'
            . '</sheetData>';

        $path = $this->saved(
            (new RawXlsx())
                ->sharedStrings(['Sunset', 'Žluťoučký kůň & <tags>', "Two\nlines", '<si><r><t xml:space="preserve">Rich </t></r><r><t>text</t></r></si>'])
                ->styles([14, 'mmmm\ yyyy', '[h]:mm:ss', 'h:mm:ss', 'm/d/yyyy h:mm', 'yyyy-mm-dd'])
                ->sheet('Mixed', RawXlsx::worksheetAround($sheetData, '<dimension ref="A2:AX400"/>'))
                ->sheet('Second', RawXlsx::worksheet([1 => ['A' => RawXlsx::s(0), 'C' => RawXlsx::n('7')], 5 => ['B' => RawXlsx::inline('five')]]), 'hidden'),
        );

        self::assertSame(29, $this->assertSameAsOpenSpout($path));
    }

    public function testAWorkbookWrittenByASpreadsheetLibrary(): void
    {
        $spreadsheet = new Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->fromArray(['Puzzle', 'Pieces', 'Finished', 'Time', 'No box?'], null, 'A1');

        for ($row = 2; $row <= 60; $row++) {
            $sheet->setCellValue('A' . $row, 'Made-up puzzle ' . $row);
            $sheet->setCellValue('B' . $row, 500 * ($row % 4 + 1));
            $sheet->setCellValue('C' . $row, 45_000 + $row);
            $sheet->setCellValue('D' . $row, (1_800 + $row * 97) / 86_400);
            $sheet->setCellValue('E' . $row, $row % 3 === 0);
            $sheet->setCellValue('F' . $row, sprintf('=B%1$d/(D%1$d*1440)', $row));
        }

        $sheet->getStyle('C2:C60')->getNumberFormat()->setFormatCode('d.m.yyyy');
        $sheet->getStyle('D2:D60')->getNumberFormat()->setFormatCode('[h]:mm:ss');

        $path = $this->path();
        (new XlsxWriter($spreadsheet))->save($path);
        $spreadsheet->disconnectWorksheets();

        self::assertSame(5 + 59 * 6, $this->assertSameAsOpenSpout($path));
    }

    /**
     * @return int the number of cells both read
     */
    private function assertSameAsOpenSpout(string $path): int
    {
        $workbook = $this->open($path);
        $reader = new Reader(new Options(SHOULD_PRESERVE_EMPTY_ROWS: true, tempFolder: $this->folder . '/tmp'));
        $reader->open($path);
        $compared = 0;

        try {
            $sheets = $workbook->sheets();

            foreach ($reader->getSheetIterator() as $openSpoutSheet) {
                $sheet = $sheets[$openSpoutSheet->getIndex()];
                self::assertSame($openSpoutSheet->getName(), $sheet->title);
                self::assertSame($openSpoutSheet->isVisible(), $sheet->hidden === false);

                $theirs = [];
                $rows = $openSpoutSheet->getRowIterator();

                for ($rows->rewind(); $rows->valid(); $rows->next()) {
                    foreach ($rows->current()->cells as $index => $cell) {
                        $value = self::openSpoutValue($cell);

                        if ($value !== null) {
                            $theirs[$rows->key()][$index + 1] = $value;
                        }
                    }
                }

                $ours = [];

                foreach ($workbook->rows($sheet) as $row) {
                    foreach ($row->cells as $column => $cell) {
                        // OpenSpout has no value for a formula's saved error
                        if ($cell->kind === CellKind::Error && $cell->isFormula) {
                            continue;
                        }

                        $ours[$row->number][$column] = self::ourValue($cell, isset($theirs[$row->number][$column][2]));
                        $compared++;
                    }
                }

                self::assertSame($theirs, $ours);
            }
        } finally {
            $reader->close();
        }

        return $compared;
    }

    /**
     * @return null|list<bool|float|int|string>
     */
    private static function openSpoutValue(Cell $cell): null|array
    {
        $value = $cell instanceof FormulaCell ? $cell->getComputedValue() : $cell->getValue();

        return match (true) {
            $cell instanceof ErrorCell => ['error', $cell->getRawValue()],
            $cell instanceof EmptyCell, $value === null, $value === '' => null,
            is_string($value) => ['text', $value],
            is_int($value), is_float($value) => ['number', $value],
            is_bool($value) => ['boolean', $value],
            $value instanceof \DateInterval => ['duration', ($value->h * 3600 + $value->i * 60 + $value->s) * ($value->invert === 1 ? -1 : 1)],
            // A time of day is a moment of the day before the calendar starts
            $value instanceof \DateTimeInterface && $value->format('Y-m-d') === '1899-12-30' => ['time', (int) $value->format('H') * 3600 + (int) $value->format('i') * 60 + (int) $value->format('s')],
            $value instanceof \DateTimeInterface => ['date', $value->format('Y-m-d'), (int) $value->format('H') * 3600 + (int) $value->format('i') * 60 + (int) $value->format('s')],
            default => ['other'],
        };
    }

    /**
     * @param bool $withTime OpenSpout always hands a date's time over; we do when the cell's format shows it
     *
     * @return list<bool|float|int|string>
     */
    private static function ourValue(ReadCell $cell, bool $withTime): array
    {
        return match ($cell->kind) {
            CellKind::Text => ['text', $cell->asText()],
            CellKind::Number => ['number', $cell->asNumber()],
            CellKind::Boolean => ['boolean', $cell->asBoolean()],
            CellKind::Duration => ['duration', $cell->asSeconds()],
            CellKind::TimeOfDay => ['time', $cell->asSeconds()],
            CellKind::Error => ['error', $cell->asText()],
            CellKind::Date => $withTime ? ['date', $cell->asDate()->format('Y-m-d'), $cell->timeSeconds ?? 0] : ['date', $cell->asDate()->format('Y-m-d')],
        };
    }
}
