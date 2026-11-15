<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Services\SpreadsheetReader;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use SpeedPuzzling\Web\Exceptions\SpreadsheetUnreadable;
use SpeedPuzzling\Web\Services\SpreadsheetReader\ReadWorkbook;
use SpeedPuzzling\Web\Services\SpreadsheetReader\Xlsx\XmlFloodScanner;
use SpeedPuzzling\Web\Value\Spreadsheet\CellKind;
use SpeedPuzzling\Web\Value\Spreadsheet\CellRange;
use SpeedPuzzling\Web\Value\Spreadsheet\ReadRow;
use SpeedPuzzling\Web\Value\Spreadsheet\SpreadsheetLimits;
use SpeedPuzzling\Web\Value\Spreadsheet\SpreadsheetUnreadableReason;

/**
 * The time a file can cost is bounded - also where the XML parser itself is slow, inside one call that no check in
 * PHP sees (libxml 2.9, which production runs: a start tag of 300,000 attributes took 90 s, three million distinct
 * names 117 s, 150,000 namespace declarations in scope 11 s for every megabyte after them). XmlFloodScanner refuses
 * those shapes before the parser gets them; a budget of seconds bounds the rest; nothing relies on a PHP warning.
 *
 * Every file is made here, small on disk.
 */
final class XlsxTimeBoundTest extends TestCase
{
    use SpreadsheetFiles;

    /** A hostile file is refused in milliseconds; the bound is generous for a busy machine */
    private const float SECONDS_AT_MOST = 5.0;

    /**
     * @return iterable<string, array{string}> where the tag stands
     */
    public static function placesForATag(): iterable
    {
        yield 'on a cell' => ['cell'];
        yield 'in the head of a sheet' => ['head'];
        yield 'in the workbook part' => ['workbook'];
        yield 'in the styles' => ['styles'];
        yield 'in the shared strings' => ['strings'];
    }

    #[DataProvider('placesForATag')]
    public function testAStartTagOfThreeHundredThousandAttributesIsRefusedBeforeTheParserGetsIt(string $place): void
    {
        $attributes = '';

        for ($number = 0; $number < 300_000; $number++) {
            $attributes .= ' a' . $number . '=""';
        }

        // At its 65th attribute - long before the tag is all there
        $this->assertRefusedAtOnce($this->workbookWith($place, '<flood' . $attributes . '/>'), 'a tag with more attributes than a tag has');
    }

    /**
     * @return iterable<string, array{string, string}> what stands between two processing instructions, and where
     */
    public static function hiddenBehindAProcessingInstruction(): iterable
    {
        $attributes = '';

        for ($number = 0; $number < 120_000; $number++) {
            $attributes .= ' a' . $number . '=""';
        }

        $namespaces = '';

        for ($number = 0; $number < 150_000; $number++) {
            $namespaces .= ($number % 600 === 0 ? ($number === 0 ? '<g' : '><g') : '') . ' xmlns:p' . $number . '="u"';
        }

        foreach (['head', 'workbook', 'strings'] as $place) {
            yield '120,000 attributes behind "<?a <!-- ?>", ' . $place => ['<?a <!-- ?><flood' . $attributes . '/><?b --> ?>', $place];
            yield '120,000 attributes behind "<?a <![CDATA[ ?>", ' . $place => ['<?a <![CDATA[ ?><flood' . $attributes . '/><?b ]]> ?>', $place];
        }

        yield '150,000 namespace declarations behind "<?a <!-- ?>"' => ['<?a <!-- ?>' . $namespaces . '>' . str_repeat('</g>', 250) . '<?b --> ?>', 'head'];
        yield 'a document type behind "<?a <!-- ?>"' => ['<?a <!-- ?><!DOCTYPE x [<!ENTITY e "v">]><?b --> ?>', 'head'];
    }

    /**
     * A parser ends a processing instruction where it first closes, whatever stands in it - a scanner that took the
     * comment for real would see nothing of what follows. A workbook's parts have no processing instructions.
     */
    #[DataProvider('hiddenBehindAProcessingInstruction')]
    public function testNothingHidesBehindAProcessingInstruction(string $markup, string $place): void
    {
        $this->assertRefusedAtOnce($this->workbookWith($place, $markup), 'a processing instruction');
    }

    public function testTagsAndTextsAsLongAsRealWorkbooksHaveThemAreRead(): void
    {
        // A conditional format over thousands of ranges: one start tag of 100 KiB, after the rows
        $ranges = rtrim(str_repeat('A1:B2 C3:D4 ', 8_600));
        $longest = str_repeat('&', 32_767);
        $sheet = RawXlsx::worksheet(
            [1 => ['A' => RawXlsx::inline($longest), 'B' => RawXlsx::s(0), 'C' => RawXlsx::n('7')]],
            '',
            '<mergeCells count="1"><mergeCell ref="D1:E1"/></mergeCells><conditionalFormatting sqref="' . $ranges . '"><cfRule type="expression" priority="1"><formula>TRUE</formula></cfRule></conditionalFormatting>'
            . '<dataValidations count="1"><dataValidation type="list" sqref="' . $ranges . '"><formula1>"a,b"</formula1></dataValidation></dataValidations>',
        );

        self::assertGreaterThan(100_000, strlen($ranges));
        // 32,767 ampersands are 163,835 bytes as a workbook writes them - and one cell, as long as a cell can be
        self::assertGreaterThan(131_072, substr_count($sheet, '&amp;') * 5);

        $workbook = $this->open($this->saved((new RawXlsx())->sharedStrings([$longest])->sheet('Sheet', $sheet)), limits: new SpreadsheetLimits(maxCompressionRatio: 1_000_000));
        $rows = $this->rowsOf($workbook);

        self::assertSame($longest, self::cell($rows, 1, 1)->value);
        self::assertSame($longest, self::cell($rows, 1, 2)->value);
        self::assertSame(7, self::cell($rows, 1, 3)->value);
        self::assertSame('D1:E1', $workbook->mergedRanges($workbook->sheets()[0])[0]->toA1());

        // A sheet without merged ranges: the pass for them reads on to the first element that stands after them
        $without = $this->open($this->saved((new RawXlsx())->sheet('Sheet', RawXlsx::worksheet([1 => ['A' => RawXlsx::n('7')]], '', '<conditionalFormatting sqref="' . $ranges . '"/>'))), limits: new SpreadsheetLimits(maxCompressionRatio: 1_000_000));

        self::assertSame([], $without->mergedRanges($without->sheets()[0]));
        self::assertSame(7, self::cell($this->rowsOf($without), 1, 1)->value);
    }

    public function testElementsNestedDeeperThanTheParserGoesAreDamagedNotACellTooLarge(): void
    {
        // The parser's message names its option "XML_PARSE_HUGE" - which has nothing to do with a text that is huge
        $path = $this->saved((new RawXlsx())->sheet('Sheet', RawXlsx::worksheetAround('<sheetData><row r="1"><c r="A1">' . str_repeat('<is>', 300) . str_repeat('</is>', 300) . '</c></row></sheetData>')));

        try {
            $this->rowsOf($this->open($path));
            self::fail('Three hundred elements inside one another were read.');
        } catch (SpreadsheetUnreadable $e) {
            self::assertSame(SpreadsheetUnreadableReason::Damaged, $e->reason, $e->getMessage());
        }
    }

    public function testWhatTheApplicationHadInTheParsersListOfErrorsStaysThere(): void
    {
        $prefixed = $this->saved((new RawXlsx())->sheet('Sheet', RawXlsx::worksheetAround('<sheetData>' . str_repeat('<row><x:c><x:v>1</x:v></x:c></row>', 20) . '<row><c><v>1</v></c></row></sheetData>')));
        $internal = libxml_use_internal_errors(true);
        libxml_clear_errors();

        try {
            // The application parses something of its own, keeps the complaints in the list - and then reads a file
            self::assertFalse(simplexml_load_string('<not-closed>'));
            $before = array_map(static fn (\LibXMLError $error): string => $error->message, libxml_get_errors());
            self::assertNotSame([], $before);

            self::assertCount(1, $this->rowsOf($this->open($prefixed)));

            $after = array_map(static fn (\LibXMLError $error): string => $error->message, libxml_get_errors());
            self::assertSame($before, array_slice($after, 0, count($before)));
            self::assertTrue(libxml_use_internal_errors());
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($internal);
        }

        // An application that does not keep them finds the list as empty as it left it
        self::assertCount(1, $this->rowsOf($this->open($prefixed)));
        self::assertSame([], libxml_get_errors());
        self::assertSame($internal, libxml_use_internal_errors());
    }

    public function testATagOfMoreAttributesThanATagHasIsRefused(): void
    {
        $attributes = static function (int $count): string {
            $attributes = '';

            for ($number = 0; $number < $count; $number++) {
                $attributes .= ' a' . $number . '="1"';
            }

            return $attributes;
        };

        // Sixty-four made-up attributes on every cell: unusual, and read (in a time that grows with the file, no faster)
        $fits = $this->saved((new RawXlsx())->sheet('Sheet', RawXlsx::worksheetAround('<sheetData>' . str_repeat('<row><c' . $attributes(XmlFloodScanner::MAX_ATTRIBUTES) . '><v>7</v></c></row>', 2_000) . '</sheetData>')));

        self::assertCount(2_000, $this->rowsOf($this->open($fits)));

        $this->assertRefusedAtOnce(
            $this->saved((new RawXlsx())->sheet('Sheet', RawXlsx::worksheetAround('<sheetData><row><c' . $attributes(XmlFloodScanner::MAX_ATTRIBUTES + 1) . '><v>7</v></c></row></sheetData>'))),
            'a tag with more attributes than a tag has',
        );
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function distinctNames(): iterable
    {
        yield 'element names, in the head of a sheet' => ['head', 'elements'];
        yield 'attribute names, in the head of a sheet' => ['head', 'attributes'];
        yield 'element names, in the workbook part' => ['workbook', 'elements'];
        yield 'element names, among the cells' => ['cell', 'elements'];
    }

    #[DataProvider('distinctNames')]
    public function testHundredsOfThousandsOfDistinctNamesAreRefusedBeforeTheParserGetsThem(string $place, string $kind): void
    {
        $flood = '';

        for ($number = 0; $number < 300_000; $number += 30) {
            if ($kind === 'elements') {
                for ($name = $number; $name < $number + 30; $name++) {
                    $flood .= '<n' . $name . '/>';
                }
            } else {
                $flood .= '<x';

                for ($name = $number; $name < $number + 30; $name++) {
                    $flood .= ' q' . $name . '=""';
                }

                $flood .= '/>';
            }
        }

        $this->assertRefusedAtOnce($this->workbookWith($place, $flood), 'more distinct names than a part has');
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function placesForBlankTexts(): iterable
    {
        yield 'in the head of a sheet' => ['head'];
        yield 'among the cells' => ['cell'];
        yield 'in the shared strings' => ['strings'];
    }

    /**
     * The parser keeps every distinct blank text of under 60 bytes in its dictionary: 1.5 million different ones of
     * 21 bytes between tags were stopped only by the clock. A fifth of that here - the file is refused at its 1,001st.
     */
    #[DataProvider('placesForBlankTexts')]
    public function testThousandsOfDistinctBlankTextsAreRefusedBeforeTheParserGetsThem(string $place): void
    {
        // Three times the cap proves the refusal; written to the part in pieces - a test must not need the memory
        // the attack is about
        $flood = static function (): \Generator {
            $blank = [' ', "\t", "\n"];

            for ($number = 0; $number < 3 * XmlFloodScanner::MAX_BLANK_RUNS; $number++) {
                $text = '';

                // 21 bytes that spell the number in base 3 - every text another one
                for ($digit = 0, $rest = $number; $digit < 21; $digit++, $rest = intdiv($rest, 3)) {
                    $text .= $blank[$rest % 3];
                }

                yield $text . '<f/>';
            }
        };

        $path = $this->workbookWithStreamed($place, $flood());
        $started = hrtime(true);

        $this->assertRefusedAtOnce($path, XmlFloodScanner::TOO_MANY_BLANK_RUNS);

        self::assertLessThan(1.0, (hrtime(true) - $started) / 1e9, 'Refused, but not at once.');
    }

    public function testAPrettyPrintedSheetOfFiftyThousandRowsIsRead(): void
    {
        // A line and an indentation of its depth before every element, as a tool that formats XML writes them -
        // 450,000 runs of white space, four different ones
        $rows = static function (): \Generator {
            for ($row = 1; $row <= 50_000; $row++) {
                $xml = "\n  <row r=\"{$row}\">";

                foreach (['A', 'B', 'C', 'D'] as $column) {
                    $xml .= "\n    <c r=\"{$column}{$row}\">\n      <v>{$row}</v>\n    </c>";
                }

                yield $xml . "\n  </row>";
            }

            yield "\n";
        };

        $workbook = $this->open($this->workbookWithStreamed('rows', $rows()), limits: new SpreadsheetLimits(maxCompressionRatio: 1_000_000));
        $count = 0;
        $lastNumber = 0;
        $lastValue = null;

        foreach ($workbook->rows($workbook->sheets()[0]) as $row) {
            $count++;
            $lastNumber = $row->number;
            $lastValue = $row->cell(4)?->value;
        }

        self::assertSame(50_000, $count);
        self::assertSame(50_000, $lastNumber);
        self::assertSame(50_000, $lastValue);
        self::assertSame(200_000, $workbook->cellsRead());
    }

    public function testARunOfWhiteSpaceTheStreamCutsInTwoIsCountedAsOne(): void
    {
        $scanner = new XmlFloodScanner(131_072);
        $document = '<a>';

        // More different runs than a part has, all but the first cut by a chunk's end
        for ($number = 0; $number <= XmlFloodScanner::MAX_BLANK_RUNS; $number++) {
            $document .= str_repeat(' ', 1 + $number % 30) . str_repeat("\n", 1 + intdiv($number, 30)) . '<c/>';
        }

        $refused = null;

        foreach (str_split($document, 7) as $chunk) {
            $refused ??= $scanner->scan($chunk);
        }

        self::assertSame(XmlFloodScanner::TOO_MANY_BLANK_RUNS, $refused);

        // The same runs over and over are no flood, however the stream is cut
        $scanner = new XmlFloodScanner(131_072);

        foreach (str_split('<a>' . str_repeat("\n    <c/>\n  <c/>", 2_000) . '</a>', 5) as $chunk) {
            self::assertNull($scanner->scan($chunk));
        }
    }

    public function testThousandsOfNamespaceDeclarationsInScopeAreRefusedBeforeTheParserGetsThem(): void
    {
        $nested = static function (int $levels, int $perTag): array {
            $open = '';
            $close = '';
            $number = 0;

            for ($level = 0; $level < $levels; $level++) {
                $open .= '<g';

                for ($declaration = 0; $declaration < $perTag; $declaration++) {
                    $open .= ' xmlns:p' . $number++ . '="urn:made-up"';
                }

                $open .= '>';
                $close .= '</g>';
            }

            return [$open, $close];
        };
        $cells = '<sheetData>' . str_repeat('<row><c/><c/><c/><c/></row>', 50_000) . '</sheetData>';

        // 150,000 in scope, 600 on a tag
        [$open, $close] = $nested(250, 600);
        $this->assertRefusedAtOnce($this->saved((new RawXlsx())->sheet('Sheet', RawXlsx::worksheetAround($open . $cells . $close))), 'a tag with more attributes than a tag has');

        // 15,000 in scope, on tags that are tags like any other
        [$open, $close] = $nested(250, 60);
        $this->assertRefusedAtOnce($this->saved((new RawXlsx())->sheet('Sheet', RawXlsx::worksheetAround($open . $cells . $close))), 'more namespace declarations than a part has');

        // A handful on a few elements, as real workbooks have them: read
        [$open, $close] = $nested(3, 8);
        $rows = $this->rowsOf($this->open($this->saved((new RawXlsx())->sheet('Sheet', RawXlsx::worksheetAround($open . '<sheetData><row><c><v>1</v></c></row></sheetData>' . $close)))));

        self::assertSame(1, self::cell($rows, 1, 1)->value);
    }

    public function testElementsWithAPrefixNobodyDeclaredAreNotReadByTheHundredThousand(): void
    {
        $path = $this->saved((new RawXlsx())->sheet('Sheet', RawXlsx::worksheetAround('<sheetData>' . str_repeat('<row><x:c><x:v>1</x:v></x:c></row>', 100_000) . '</sheetData>')));
        $warnings = $this->warningsWhile(function () use ($path): void {
            $this->assertRefusedAtOnce($path, 'the parser complains about over and over');
        });

        // The parser complains about every one of them - into a list the reader counts, never as a PHP warning
        self::assertSame([], $warnings);

        // A prefix that is declared is a spelling like any other
        $declared = str_replace('<worksheet ', '<worksheet xmlns:x="http://schemas.openxmlformats.org/spreadsheetml/2006/main" ', RawXlsx::worksheetAround('<x:sheetData>' . str_repeat('<x:row><x:c><x:v>1</x:v></x:c></x:row>', 5_000) . '</x:sheetData>'));

        self::assertCount(5_000, $this->rowsOf($this->open($this->saved((new RawXlsx())->sheet('Sheet', $declared)))));
    }

    public function testWhatTheScannerLetsThroughIsWhatAWorkbookIsMadeOf(): void
    {
        // Comments, CDATA sections and made-up elements between the cells, of every length - so that every kind of
        // markup is cut by the edge of a chunk somewhere
        $rows = '';

        for ($row = 1; $row <= 3_000; $row++) {
            $rows .= sprintf(
                '<row r="%1$d" spans="1:3"><c r="A%1$d" t="inlineStr"><is><t xml:space="preserve"><![CDATA[text %1$d <b> & "more" %2$s]]></t></is></c>'
                . '<!-- a comment %2$s with <tags>, "quotes" and a <?made-up instruction="%1$d"?> -->'
                . '<c r="B%1$d" made-up="%2$s &gt; 1" other=\'single "quoted" > 2\'><v>%1$d</v></c><extra note="%2$s"><deeper/></extra>'
                . '<c r="C%1$d" t="str"><f>IF(A%1$d ="x", B%1$d ="y", "z")</f><v>a = "b" &lt; %1$d</v></c></row>',
                $row,
                str_repeat('é', $row % 97),
            );
        }

        $sheet = "\xEF\xBB\xBF<?xml version='1.0' encoding='utf-8' standalone='yes'?>\n<!-- " . str_repeat('a long comment ', 2_000) . "-->\n"
            . '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><sheetData>' . $rows . '</sheetData></worksheet>';
        $read = $this->rowsOf($this->open($this->saved((new RawXlsx())->sheet('Sheet', $sheet))));

        self::assertCount(3_000, $read);

        foreach ([1, 96, 97, 1_500, 3_000] as $row) {
            self::assertSame(sprintf('text %d <b> & "more" %s', $row, str_repeat('é', $row % 97)), self::cell($read, $row, 1)->value);
            self::assertSame($row, self::cell($read, $row, 2)->value);
            self::assertSame(sprintf('a = "b" < %d', $row), self::cell($read, $row, 3)->value);
            self::assertTrue(self::cell($read, $row, 3)->isFormula);
        }
    }

    /**
     * @return iterable<string, array{string, SpreadsheetUnreadableReason}>
     */
    public static function encodings(): iterable
    {
        yield 'says ISO-8859-1' => ['iso', SpreadsheetUnreadableReason::Damaged];
        yield 'says windows-1250' => ['windows', SpreadsheetUnreadableReason::Damaged];
        yield 'is UTF-16 with a mark' => ['utf16', SpreadsheetUnreadableReason::Damaged];
        yield 'is UTF-16 without a mark' => ['utf16-bare', SpreadsheetUnreadableReason::Damaged];
        yield 'holds bytes that are no UTF-8' => ['bytes', SpreadsheetUnreadableReason::Damaged];
    }

    #[DataProvider('encodings')]
    public function testAPartThatIsNotUtf8IsDamaged(string $kind, SpreadsheetUnreadableReason $reason): void
    {
        $sheet = RawXlsx::worksheet([1 => ['A' => RawXlsx::inline('Žluťoučký')]]);
        $sheet = match ($kind) {
            'iso' => str_replace('encoding="UTF-8"', 'encoding="ISO-8859-1"', $sheet),
            'windows' => str_replace('encoding="UTF-8"', "encoding='windows-1250'", $sheet),
            'utf16' => "\xFF\xFE" . mb_convert_encoding(str_replace('encoding="UTF-8"', 'encoding="UTF-16"', $sheet), 'UTF-16LE', 'UTF-8'),
            'utf16-bare' => mb_convert_encoding(str_replace('encoding="UTF-8"', 'encoding="UTF-16"', $sheet), 'UTF-16BE', 'UTF-8'),
            default => str_replace('Žluťoučký', "\xDElu\x9Dou\xE8k\xFD", $sheet),
        };

        try {
            $this->rowsOf($this->open($this->saved((new RawXlsx())->sheet('Sheet', $sheet))));
            self::fail('A part that is not UTF-8 was read.');
        } catch (SpreadsheetUnreadable $e) {
            self::assertSame($reason, $e->reason, $e->getMessage());
        }
    }

    public function testTheReadersSecondsAreABudget(): void
    {
        $path = $this->saved((new RawXlsx())->sheet('Sheet', RawXlsx::worksheetAround(
            '<sheetData>' . str_repeat('<row><c><v>1</v></c><c><v>2</v></c><c><v>3</v></c><c><v>4</v></c></row>', 150_000) . '</sheetData>',
            '',
            '<mergeCells count="1"><mergeCell ref="A1:B1"/></mergeCells>',
        )));
        $limits = new SpreadsheetLimits(maxRows: 1_000_000, maxRowsPerSheet: 1_000_000, maxCompressionRatio: 1_000_000);

        $operations = [
            // The rows are streamed …
            'rows' => static fn (ReadWorkbook $workbook): int => iterator_count($workbook->rows($workbook->sheets()[0])),
            // … or the parser skips them by itself, where no loop in PHP runs (the clock is asked on every chunk on
            // its way to the parser): under the merged ranges, above a window
            'merged ranges' => static fn (ReadWorkbook $workbook): int => count($workbook->mergedRanges($workbook->sheets()[0])),
            'a window at the end' => static fn (ReadWorkbook $workbook): int => iterator_count($workbook->rows($workbook->sheets()[0], new CellRange(1, 149_990, 4, 150_000))),
        ];

        foreach ($operations as $name => $operation) {
            // In time, it reads - and says how long the reader worked on it
            $workbook = $this->open($path, limits: $limits);
            $opened = $workbook->secondsRead();
            self::assertSame(['rows' => 150_000, 'merged ranges' => 1, 'a window at the end' => 11][$name], $operation($workbook));
            $needed = $workbook->secondsRead() - $opened;
            self::assertGreaterThan(0.0, $needed, $name);
            $workbook->close();

            // With a tenth of that: refused on the way
            $this->assertTooSlow(function () use ($path, $limits, $opened, $needed, $operation): void {
                $operation($this->open($path, limits: $limits->withConsumed(0, 0, 0, $limits->maxReadSeconds - $opened - $needed / 10)));
            });
        }

        // No time at all: refused when the workbook is opened
        $this->assertTooSlow(fn () => $this->open($path, limits: $limits->withConsumed(0, 0, 0, $limits->maxReadSeconds)));
    }

    public function testWhatTheCallerDoesBetweenTwoRowsIsNotTheReadersTime(): void
    {
        $workbook = $this->open($this->saved((new RawXlsx())->sheet('Sheet', RawXlsx::worksheet([
            1 => ['A' => RawXlsx::n('1')], 2 => ['A' => RawXlsx::n('2')], 3 => ['A' => RawXlsx::n('3')], 4 => ['A' => RawXlsx::n('4')],
        ]))), limits: new SpreadsheetLimits(maxReadSeconds: 0.2));
        $started = hrtime(true);
        $rows = 0;

        foreach ($workbook->rows($workbook->sheets()[0]) as $row) {
            // The job writes the row to the database
            usleep(100_000);
            $rows++;
        }

        // 0.4 s went by - of a budget of 0.2 s - and none of it was the reader's
        self::assertSame(4, $rows);
        self::assertGreaterThan(0.39, (hrtime(true) - $started) / 1e9);
        self::assertLessThan(0.2, $workbook->secondsRead());
    }

    public function testBytesAndSecondsAreCarriedFromOneJobToTheNext(): void
    {
        $sheet = RawXlsx::worksheetAround('<sheetData>' . str_repeat('<row><c><v>1</v></c><c t="s"><v>0</v></c></row>', 2_000) . '</sheetData>');
        $path = $this->saved((new RawXlsx())->sharedStrings(['a text'])->sheet('One', $sheet)->sheet('Two', $sheet)->sheet('Three', $sheet));
        $limits = new SpreadsheetLimits(maxReadBytes: 250_000);

        // The first job: the structure, the first sheet and the shared strings
        $first = $this->open($path, limits: $limits);
        self::assertGreaterThan(0, $first->bytesRead());
        $opened = $first->bytesRead();
        self::assertCount(2_000, $this->rowsOf($first, 1));
        self::assertGreaterThan($opened + 80_000, $first->bytesRead());
        self::assertGreaterThan(0.0, $first->secondsRead());

        $left = $limits->withConsumed($first->rowsRead(), $first->cellsRead(), $first->bytesRead(), $first->secondsRead());
        self::assertSame(250_000 - $first->bytesRead(), $left->maxReadBytes);
        self::assertSame($limits->maxReadSeconds - $first->secondsRead(), $left->maxReadSeconds);
        $first->close();

        // The second job reads the second sheet out of what is left …
        $second = $this->open($path, limits: $left);
        self::assertCount(2_000, $this->rowsOf($second, 2));
        $left = $left->withConsumed($second->rowsRead(), $second->cellsRead(), $second->bytesRead(), $second->secondsRead());
        $second->close();

        // … and for the third sheet the file's budget is used up: a file can not be read once more for every job
        self::assertLessThan(80_000, $left->maxReadBytes);

        try {
            $this->rowsOf($this->open($path, limits: $left), 3);
            self::fail('A file was read beyond its budget of bytes.');
        } catch (SpreadsheetUnreadable $e) {
            self::assertSame(SpreadsheetUnreadableReason::TooLarge, $e->reason);
        }
    }

    public function testACorruptPackedPartIsDamagedWithoutAWarningInAnyEnvironment(): void
    {
        $rows = [];

        // Text that packs badly: the sheet's packed bytes are long enough to break in their middle
        for ($row = 1; $row <= 3_000; $row++) {
            $rows[$row] = ['A' => RawXlsx::n((string) ($row * 7_919)), 'B' => RawXlsx::inline(md5('made-up ' . $row))];
        }

        $workbook = (new RawXlsx())->sharedStrings(array_map(static fn (int $n): string => md5('text ' . $n), range(1, 3_000)))->sheet('Sheet', RawXlsx::worksheet($rows + [3_001 => ['A' => RawXlsx::s(5)]]));

        foreach (['xl/worksheets/sheet1.xml', 'xl/sharedStrings.xml', 'xl/styles.xml', 'xl/workbook.xml'] as $part) {
            $path = $this->saved($part === 'xl/styles.xml' ? $workbook->styles(array_map(static fn (int $n): string => '0.' . str_repeat('0', $n % 9) . '"' . md5((string) $n) . '"', range(1, 400))) : $workbook);

            if ($part === 'xl/workbook.xml') {
                $padded = str_replace('<workbookPr/>', '<workbookPr/><!--' . implode('', array_map(static fn (int $n): string => md5((string) $n), range(1, 200))) . '-->', $workbook->parts()[$part]);
                $path = $this->saved($workbook->part($part, $padded));
            }

            RawXlsx::corrupt($path, $part);

            // As in production: warnings do not throw - and none may reach the application's handler, silenced or not
            $warnings = $this->warningsWhile(function () use ($path, $part): void {
                $this->assertDamaged($path, $part);
            });
            self::assertSame([], $warnings, $part);

            // As in development: every warning becomes an exception - there is none to become one
            set_error_handler(static fn (int $level, string $message, string $file, int $line): bool => throw new \ErrorException($message, 0, $level, $file, $line));

            try {
                $this->assertDamaged($path, $part);
            } finally {
                restore_error_handler();
            }
        }
    }

    /**
     * @return iterable<string, array{int}>
     */
    public static function otherPackingMethods(): iterable
    {
        yield 'bzip2' => [12];
        yield 'LZMA' => [14];
        yield 'Zstandard' => [93];
        yield 'XZ' => [95];
        yield 'deflate64' => [9];
    }

    #[DataProvider('otherPackingMethods')]
    public function testOnlyStoredAndDeflatedPartsAreUnpacked(int $method): void
    {
        $workbook = (new RawXlsx())->sheet('Sheet', RawXlsx::worksheet([1 => ['A' => RawXlsx::n('1')]]))->part('xl/media/image1.png', str_repeat('picture ', 100));

        foreach (['xl/worksheets/sheet1.xml', 'xl/media/image1.png'] as $part) {
            $path = $this->saved($workbook);
            RawXlsx::declareMethod($path, $part, $method);

            try {
                $this->open($path);
                self::fail('A part packed by another method was accepted.');
            } catch (SpreadsheetUnreadable $e) {
                self::assertSame(SpreadsheetUnreadableReason::UnsupportedFormat, $e->reason);
            }
        }
    }

    public function testACellOfAHundredThousandRunsOfTextIsReadInOneStraightPass(): void
    {
        $runs = str_repeat('<r><rPr><b/></rPr><t xml:space="preserve">a</t></r><r><t> b </t></r>', 60_000);
        $path = $this->saved((new RawXlsx())->sheet('Sheet', RawXlsx::worksheetAround('<sheetData><row r="1"><c r="A1" t="inlineStr"><is>' . $runs . '</is></c></row></sheetData>')));
        $started = hrtime(true);

        $rows = $this->rowsOf($this->open($path, limits: new SpreadsheetLimits(maxCompressionRatio: 1_000_000)));

        self::assertSame(str_repeat('ab', 60_000), self::cell($rows, 1, 1)->value);
        self::assertLessThan(self::SECONDS_AT_MOST, (hrtime(true) - $started) / 1e9);
    }

    public function testNoNumberOfAnyDateSystemMakesTheReaderFail(): void
    {
        $serials = [
            '0', '0.25', '0.9999999', '1', '59', '60', '61', '45356', '45356.5', '2957003', '2957003.5', '2957003.99999', '2957003.999999999',
            '2957004', '2957004.5', '2958465', '2958465.5', '2958465.99999', '2958465.999999999', '2958466', '2958467.25', '3000000',
            '99999999', '1E+15', '1E+300', '1.7976931348623157E+308', '-0.5', '-1', '-45356', '-2958466', '-1E+300', '1E-300', '4.9E-324',
            '999999999999999999', '-999999999999999999', '9999999999999999999999', '0.000011574', '1e5', '.5', '+7', '0x1A', 'NaN', 'INF', '1e999',
        ];
        $formats = [14, 'm/d/yyyy h:mm:ss', 'mmmm\ yyyy', 'yyyy', 'm/d', 21, 'h:mm AM/PM', '[h]:mm:ss', '[mm]:ss', '0.00', '0000000000000', '@'];

        foreach ([false, true] as $from1904) {
            $rows = [];

            foreach ($serials as $index => $serial) {
                $rows[$index + 1] = [];

                foreach ($formats as $style => $format) {
                    $rows[$index + 1][CellRange::columnLetters($style + 1)] = RawXlsx::n($serial, $style + 1);
                }
            }

            $workbook = (new RawXlsx())->styles($formats)->sheet('Extremes', RawXlsx::worksheet($rows));
            $read = $this->rowsOf($this->open($this->saved($from1904 ? $workbook->date1904() : $workbook)));
            $lastDay = $from1904 ? 2_957_003 : 2_958_465;

            self::assertCount(count($serials), $read);

            foreach ($read as $number => $row) {
                $serial = $serials[$number - 1];

                foreach ($row->cells as $cell) {
                    // Whatever it is, it has a value of its kind and a text to show
                    self::assertNotSame('', $cell->shown, $serial);

                    if ($cell->kind === CellKind::Date) {
                        self::assertTrue(is_numeric($serial) && (float) $serial >= 0 && (float) $serial < $lastDay + 1, $serial);
                        self::assertGreaterThanOrEqual($from1904 ? 1904 : 1899, (int) $cell->asDate()->format('Y'), $serial);
                        self::assertLessThanOrEqual(9999, (int) $cell->asDate()->format('Y'), $serial);
                        self::assertMatchesRegularExpression('/^(\d{4}|--\d{2})/', $cell->shown, $serial);
                    }
                }
            }

            // The last day there is, and the first that is no day any more
            $lastRow = (int) array_search((string) $lastDay, $serials, true) + 1;
            $beyondRow = (int) array_search((string) ($lastDay + 1), $serials, true) + 1;

            self::assertSame('9999-12-31', self::cell($read, $lastRow, 1)->shown);
            self::assertSame(CellKind::Number, self::cell($read, $beyondRow, 1)->kind);
            self::assertSame(CellKind::Number, self::cell($read, $beyondRow, 2)->kind);
            // The last second of the year 9999, rounded up, is no date any more - and no error either
            $roundedRow = (int) array_search($lastDay . '.999999999', $serials, true) + 1;
            self::assertSame('9999-12-31', self::cell($read, $roundedRow, 1)->shown);
            self::assertSame(CellKind::Number, self::cell($read, $roundedRow, 2)->kind);
        }
    }

    public function testSeveralWindowsAreReadInOnePass(): void
    {
        $cells = [];

        for ($row = 1; $row <= 2_000; $row++) {
            $cells[$row] = ['A' => RawXlsx::n((string) $row), 'B' => RawXlsx::inline('left ' . $row), 'D' => RawXlsx::n((string) ($row * 2)), 'E' => RawXlsx::inline('right ' . $row), 'G' => RawXlsx::inline('outside')];
        }

        $path = $this->saved((new RawXlsx())->sheet('Side by side', RawXlsx::worksheet($cells), tables: [RawXlsx::table('Left', 'A10:B1500'), RawXlsx::table('Right', 'D5:E20'), RawXlsx::table('Below', 'D1990:E2000')]));
        $workbook = $this->open($path);
        $sheet = $workbook->sheets()[0];
        self::assertSame(['Right', 'Left', 'Below'], array_column($sheet->tables, 'name'));
        // In the order the caller wants them, not the sheet's
        $windows = [$sheet->tables[0]->range, $sheet->tables[1]->range, $sheet->tables[2]->range];
        $opened = $workbook->bytesRead();

        $perWindow = [0 => [], 1 => [], 2 => []];
        $order = [];

        foreach ($workbook->rows($sheet, ...$windows) as $window => $row) {
            $perWindow[$window][] = $row;
            $order[] = [$row->number, $window];
        }

        $onePass = $workbook->bytesRead() - $opened;

        self::assertSame(range(5, 20), array_map(static fn (ReadRow $row): int => $row->number, $perWindow[0]));
        self::assertSame([4, 5], array_keys($perWindow[0][0]->cells));
        self::assertSame('right 5', $perWindow[0][0]->cells[5]->value);
        self::assertSame(range(10, 1_500), array_map(static fn (ReadRow $row): int => $row->number, $perWindow[1]));
        self::assertSame([1, 2], array_keys($perWindow[1][0]->cells));
        self::assertSame('left 1500', $perWindow[1][1_490]->cells[2]->value);
        self::assertSame(range(1_990, 2_000), array_map(static fn (ReadRow $row): int => $row->number, $perWindow[2]));
        // Sheet order; a row that two windows reach into comes once for each, in the windows' order
        self::assertSame([[5, 0], [6, 0]], array_slice($order, 0, 2));
        self::assertSame([[10, 0], [10, 1], [11, 0], [11, 1]], array_slice($order, 5, 4));
        self::assertSame(16 + 1_491 + 11, $workbook->rowsRead());
        self::assertSame(2 * (16 + 1_491 + 11), $workbook->cellsRead());

        // One pass over the sheet for the three of them …
        self::assertLessThan(1.1 * strlen(RawXlsx::worksheet($cells)), $onePass);

        // … where three calls are three passes (each as far as its window reaches) - and count against the budget so
        foreach ($windows as $window) {
            iterator_to_array($workbook->rows($sheet, $window), false);
        }

        self::assertGreaterThan(1.5 * $onePass, $workbook->bytesRead() - $opened - $onePass);
    }

    public function testAWorkbookClosedWhileASheetIsStreamedHandsOutNoFurtherRow(): void
    {
        $strings = [];
        $rows = [];

        for ($index = 0; $index < 6_000; $index++) {
            $strings[] = 'Made-up puzzle ' . $index;
            $rows[$index + 1] = ['A' => RawXlsx::s($index)];
        }

        $workbook = $this->open($this->saved((new RawXlsx())->sharedStrings($strings)->sheet('Sheet', RawXlsx::worksheet($rows))));
        $read = 0;

        foreach ($workbook->rows($workbook->sheets()[0]) as $row) {
            if (++$read === 10) {
                // The shared strings are in the temporary folder by now
                self::assertNotSame([], glob($this->folder . '/tmp/*'));
                $workbook->close();
            }
        }

        self::assertSame(10, $read);
        self::assertSame([], glob($this->folder . '/tmp/*'));
        self::assertSame(10, $workbook->rowsRead());
    }

    /**
     * A small workbook with something hostile at a place of it.
     */
    private function workbookWith(string $place, string $markup): string
    {
        $workbook = (new RawXlsx())->sharedStrings(['text'])->styles([14]);
        $cells = '<row r="1"><c r="A1" t="s"><v>0</v></c></row>';

        return $this->saved(match ($place) {
            'cell' => $workbook->sheet('Sheet', RawXlsx::worksheetAround('<sheetData><row r="1"><c r="A1" t="s"><v>0</v>' . $markup . '</c></row></sheetData>')),
            'head' => $workbook->sheet('Sheet', RawXlsx::worksheetAround('<sheetData>' . $cells . '</sheetData>', '<sheetPr>' . $markup . '</sheetPr>')),
            'workbook' => (static function () use ($workbook, $cells, $markup): RawXlsx {
                $workbook->sheet('Sheet', RawXlsx::worksheetAround('<sheetData>' . $cells . '</sheetData>'));

                return $workbook->part('xl/workbook.xml', str_replace('<workbookPr/>', '<workbookPr/><bookViews>' . $markup . '</bookViews>', $workbook->parts()['xl/workbook.xml']));
            })(),
            'styles' => (static function () use ($workbook, $cells, $markup): RawXlsx {
                $workbook->sheet('Sheet', RawXlsx::worksheetAround('<sheetData>' . $cells . '</sheetData>'));

                return $workbook->part('xl/styles.xml', str_replace('<fonts ', $markup . '<fonts ', $workbook->parts()['xl/styles.xml']));
            })(),
            default => (static function () use ($workbook, $cells, $markup): RawXlsx {
                $workbook->sheet('Sheet', RawXlsx::worksheetAround('<sheetData>' . $cells . '</sheetData>'));

                return $workbook->part('xl/sharedStrings.xml', str_replace('<si>', $markup . '<si>', $workbook->parts()['xl/sharedStrings.xml']));
            })(),
        });
    }

    /**
     * A small workbook with something large at a place of it - written to the part piece by piece, never held as one
     * string (a test worker carries other tests' memory).
     *
     * @param string $place 'head' and 'cell' of the sheet, 'strings' (shared strings), 'rows' (the sheet's rows themselves)
     * @param iterable<string> $pieces
     */
    private function workbookWithStreamed(string $place, iterable $pieces): string
    {
        $marker = '<streamed-here/>';
        $entry = $place === 'strings' ? 'xl/sharedStrings.xml' : 'xl/worksheets/sheet1.xml';
        $path = $place === 'rows'
            ? $this->saved((new RawXlsx())->sheet('Sheet', RawXlsx::worksheetAround('<sheetData>' . $marker . '</sheetData>')))
            : $this->workbookWith($place, $marker);

        $zip = new \ZipArchive();
        self::assertTrue($zip->open($path));
        $part = $zip->getFromName($entry);
        self::assertIsString($part);
        [$before, $after] = explode($marker, $part, 2) + [1 => ''];

        $partPath = $path . '.part.xml';
        $file = fopen($partPath, 'wb');
        self::assertIsResource($file);
        fwrite($file, $before);

        foreach ($pieces as $piece) {
            fwrite($file, $piece);
        }

        fwrite($file, $after);
        fclose($file);

        $zip->addFile($partPath, $entry, 0, 0, \ZipArchive::FL_OVERWRITE);
        $zip->close();
        unlink($partPath);

        return $path;
    }

    private function assertRefusedAtOnce(string $path, string $why): void
    {
        $started = hrtime(true);

        try {
            $workbook = $this->open($path, limits: new SpreadsheetLimits(maxCompressionRatio: 1_000_000));
            iterator_count($workbook->rows($workbook->sheets()[0]));
            self::fail('A file built to be slow to read was read.');
        } catch (SpreadsheetUnreadable $e) {
            self::assertSame(SpreadsheetUnreadableReason::Damaged, $e->reason, $e->getMessage());
            self::assertStringContainsString($why, $e->getMessage());
        }

        self::assertLessThan(self::SECONDS_AT_MOST, (hrtime(true) - $started) / 1e9);
    }

    private function assertDamaged(string $path, string $part): void
    {
        try {
            $workbook = $this->open($path);
            $this->rowsOf($workbook);
            self::fail(sprintf('A workbook whose part "%s" can not be unpacked was read.', $part));
        } catch (SpreadsheetUnreadable $e) {
            self::assertSame(SpreadsheetUnreadableReason::Damaged, $e->reason, $e->getMessage());
        }
    }

    /**
     * @param \Closure(): mixed $read
     */
    private function assertTooSlow(\Closure $read): void
    {
        try {
            $read();
            self::fail('A file was read beyond its budget of seconds.');
        } catch (SpreadsheetUnreadable $e) {
            self::assertSame(SpreadsheetUnreadableReason::TooSlow, $e->reason, $e->getMessage());
        }
    }

    /**
     * Every warning or notice PHP hands to the application's error handler while something runs - also the ones
     * silenced by "@", which a handler is called for all the same.
     *
     * @param \Closure(): void $run
     *
     * @return list<string>
     */
    private function warningsWhile(\Closure $run): array
    {
        $warnings = [];
        set_error_handler(static function (int $level, string $message) use (&$warnings): bool {
            $warnings[] = $message;

            return true;
        });

        try {
            $run();
        } finally {
            restore_error_handler();
        }

        return $warnings;
    }
}
