<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Services\SpreadsheetReader\Xlsx;

use SpeedPuzzling\Web\Exceptions\SpreadsheetUnreadable;
use SpeedPuzzling\Web\Services\SpreadsheetReader\NumberFormatClassifier;
use SpeedPuzzling\Web\Value\Spreadsheet\CellRange;
use SpeedPuzzling\Web\Value\Spreadsheet\FrozenPane;
use SpeedPuzzling\Web\Value\Spreadsheet\NumberFormat;
use SpeedPuzzling\Web\Value\Spreadsheet\ReadSheet;
use SpeedPuzzling\Web\Value\Spreadsheet\ReadTable;
use SpeedPuzzling\Web\Value\Spreadsheet\SpreadsheetLimits;

/**
 * What a workbook is made of, read from its small parts before any row: the sheets in tab order with their visibility,
 * the date system, every cell style's number format, where the shared strings are, and per sheet its used range,
 * frozen pane and Excel tables.
 *
 * Every part is found through the relationships (never by a name it "should" have) and read through
 * XlsxPackage::xml() - so whatever a relationship points at is metered and checked like any other part. Nothing here
 * is done once per tab of an untrusted workbook without a cap in front of it.
 */
final readonly class XlsxStructure
{
    /** More cell styles than Excel itself allows (64,000) is not a workbook anybody made */
    private const int MAX_CELL_STYLES = 70_000;
    private const int MAX_NUMBER_FORMATS = 10_000;
    /** Relationships of one part that are looked at (a sheet with a link in every row has tens of thousands) … */
    private const int MAX_RELATIONSHIPS_SEEN = 100_000;
    /** … and kept: only the kinds asked for */
    private const int MAX_RELATIONSHIPS_KEPT = 5_000;
    private const int MAX_TABLES = 2_000;

    /**
     * @param list<NumberFormat> $cellFormats cell style index (a cell's "s") => its number format
     * @param non-empty-list<ReadSheet> $sheets
     * @param array<int, string> $entries a sheet's position => its zip entry
     */
    private function __construct(
        public bool $date1904,
        public array $cellFormats,
        public array $sheets,
        public array $entries,
        public null|string $sharedStringsEntry,
    ) {
    }

    public static function read(XlsxPackage $package, SpreadsheetLimits $limits): self
    {
        $workbookEntry = self::workbookEntry($package);
        $relationships = self::relationships($package, $workbookEntry, ['/worksheet', '/styles', '/sharedStrings']);
        $date1904 = false;
        $sheets = [];
        $entries = [];
        $titles = [];
        $tabs = 0;
        $tablesLeft = self::MAX_TABLES;
        $root = true;

        $xml = $package->xml($workbookEntry);

        try {
            while ($xml->read()) {
                // What stands after the list of sheets (defined names, extensions) is not read
                if ($xml->reader->nodeType === \XMLReader::END_ELEMENT && $xml->reader->localName === 'sheets') {
                    break;
                }

                if ($xml->reader->nodeType !== \XMLReader::ELEMENT) {
                    continue;
                }

                $name = $xml->reader->localName;

                if ($root) {
                    $root = false;

                    if ($name !== 'workbook') {
                        throw $package->notAWorkbook();
                    }

                    continue;
                }

                if ($name === 'workbookPr') {
                    $date1904 = in_array(strtolower((string) $xml->attribute('date1904')), ['1', 'true', 'on'], true);

                    continue;
                }

                if ($name !== 'sheet') {
                    continue;
                }

                // Every tab counts - a chart sheet too: nothing is done a hundred thousand times for a hostile workbook
                if (++$tabs > $limits->maxSheets) {
                    throw SpreadsheetUnreadable::tooManySheets($limits->maxSheets);
                }

                $relationship = $relationships[(string) $xml->relationshipId()] ?? null;

                // A chart sheet, a macro sheet: tabs without cells - their parts are never opened
                if ($relationship === null || str_ends_with($relationship['type'], '/worksheet') === false) {
                    continue;
                }

                // A tab's name is 31 characters at most
                $title = mb_strcut((string) $xml->attribute('name'), 0, 255, 'UTF-8');
                $entry = $relationship['entry'];

                if (isset($titles[$title]) || $package->has($entry) === false) {
                    throw SpreadsheetUnreadable::damaged('a sheet is named twice or its part is missing');
                }

                $titles[$title] = true;
                $position = count($sheets) + 1;
                $state = (string) $xml->attribute('state');
                [$usedRange, $frozenPane] = self::sheetHead($package, $entry);
                $tables = self::tables($package, $entry, $tablesLeft);
                $tablesLeft -= count($tables);

                $sheets[] = new ReadSheet(
                    position: $position,
                    title: $title,
                    hidden: $state === 'hidden' || $state === 'veryHidden',
                    tables: $tables,
                    frozenPane: $frozenPane,
                    usedRange: $usedRange,
                );
                $entries[$position] = $entry;
            }
        } finally {
            $xml->close();
        }

        if ($sheets === []) {
            throw SpreadsheetUnreadable::damaged('the workbook has no sheet');
        }

        return new self(
            $date1904,
            self::cellFormats($package, self::target($package, $relationships, '/styles')),
            $sheets,
            $entries,
            self::target($package, $relationships, '/sharedStrings'),
        );
    }

    /**
     * The relationships of a part that point to another part of the workbook and are of a kind asked for.
     *
     * @param list<string> $typeEndings how the wanted kinds end: "/worksheet", "/table" …
     *
     * @return array<string, array{type: string, entry: string}> relationship id => …
     */
    private static function relationships(XlsxPackage $package, string $entry, array $typeEndings): array
    {
        $relationshipsEntry = XlsxPackage::relationshipsOf($entry);

        if ($package->has($relationshipsEntry) === false) {
            return [];
        }

        $relationships = [];
        $seen = 0;
        $xml = $package->xml($relationshipsEntry);

        try {
            while ($xml->read()) {
                if ($xml->reader->nodeType !== \XMLReader::ELEMENT || $xml->reader->localName !== 'Relationship') {
                    continue;
                }

                if (++$seen > self::MAX_RELATIONSHIPS_SEEN || count($relationships) >= self::MAX_RELATIONSHIPS_KEPT) {
                    break;
                }

                $type = (string) $xml->attribute('Type');
                $id = (string) $xml->attribute('Id');
                $target = (string) $xml->attribute('Target');
                $wanted = false;

                foreach ($typeEndings as $ending) {
                    $wanted = $wanted || str_ends_with($type, $ending);
                }

                // A link to the outside is no part; names longer than any writer makes are not kept
                if ($wanted === false || $xml->attribute('TargetMode') === 'External' || strlen($id) > 255 || strlen($target) > 1024) {
                    continue;
                }

                $relationships[$id] = ['type' => $type, 'entry' => XlsxPackage::resolve($entry, $target)];
            }
        } finally {
            $xml->close();
        }

        return $relationships;
    }

    /**
     * The workbook part: what the package's own relationships call the office document, else xl/workbook.xml.
     */
    private static function workbookEntry(XlsxPackage $package): string
    {
        foreach (self::relationships($package, '', ['/officeDocument']) as $relationship) {
            if (str_ends_with($relationship['type'], '/officeDocument') && $package->has($relationship['entry'])) {
                // A binary workbook is no XML at all
                if (str_ends_with($relationship['entry'], '.bin')) {
                    throw $package->notAWorkbook();
                }

                return $relationship['entry'];
            }
        }

        if ($package->has('xl/workbook.xml')) {
            return 'xl/workbook.xml';
        }

        throw $package->notAWorkbook();
    }

    /**
     * @param array<string, array{type: string, entry: string}> $relationships
     */
    private static function target(XlsxPackage $package, array $relationships, string $typeEnding): null|string
    {
        foreach ($relationships as $relationship) {
            if (str_ends_with($relationship['type'], $typeEnding) && $package->has($relationship['entry'])) {
                return $relationship['entry'];
            }
        }

        return null;
    }

    /**
     * The part of a sheet before its rows: <dimension ref="A1:M240"/> and the frozen <pane> of its first view.
     *
     * @return array{null|CellRange, null|FrozenPane}
     */
    private static function sheetHead(XlsxPackage $package, string $entry): array
    {
        $usedRange = null;
        $frozenPane = null;
        $xml = $package->xml($entry);

        try {
            while ($xml->read()) {
                if ($xml->reader->nodeType !== \XMLReader::ELEMENT) {
                    continue;
                }

                $name = $xml->reader->localName;

                if ($name === 'sheetData') {
                    break;
                }

                if ($name === 'dimension') {
                    $usedRange = CellRange::fromA1(substr((string) $xml->attribute('ref'), 0, 32));
                } elseif ($name === 'pane' && $frozenPane === null && in_array($xml->attribute('state'), ['frozen', 'frozenSplit'], true)) {
                    $rows = self::split($xml, 'ySplit', CellRange::MAX_ROW);
                    $columns = self::split($xml, 'xSplit', CellRange::MAX_COLUMN);

                    if ($rows + $columns > 0) {
                        $frozenPane = new FrozenPane($rows + 1, $columns + 1);
                    }
                }
            }
        } finally {
            $xml->close();
        }

        return [$usedRange, $frozenPane];
    }

    /**
     * How many rows or columns a pane freezes: a whole number, which some writers spell "6.0".
     */
    private static function split(XmlPart $xml, string $attribute, int $below): int
    {
        $value = $xml->attribute($attribute);

        if ($value === null) {
            return 0;
        }

        if (preg_match('/^(\d{1,7})(\.0+)?$/', $value, $match) !== 1 || (int) $match[1] >= $below) {
            throw SpreadsheetUnreadable::damaged('a frozen pane that freezes no whole number of rows or columns');
        }

        return (int) $match[1];
    }

    /**
     * The Excel tables of a sheet: its relationships name their parts, each part's root element says where it is.
     *
     * @return list<ReadTable> top to bottom, left to right
     */
    private static function tables(XlsxPackage $package, string $sheetEntry, int $atMost): array
    {
        $tables = [];

        foreach (self::relationships($package, $sheetEntry, ['/table']) as $relationship) {
            if (count($tables) >= $atMost) {
                break;
            }

            if ($package->has($relationship['entry']) === false) {
                continue;
            }

            $xml = $package->xml($relationship['entry']);

            try {
                while ($xml->read()) {
                    if ($xml->reader->nodeType !== \XMLReader::ELEMENT) {
                        continue;
                    }

                    $range = $xml->reader->localName === 'table' ? CellRange::fromA1(substr((string) $xml->attribute('ref'), 0, 32)) : null;

                    if ($range !== null) {
                        $headerRows = $xml->number('headerRowCount', 1, CellRange::MAX_ROW);
                        $totalsRows = min($xml->number('totalsRowCount', 0, CellRange::MAX_ROW), $range->rows() - 1);

                        $tables[] = new ReadTable(
                            name: mb_strcut((string) ($xml->attribute('displayName') ?? $xml->attribute('name')), 0, 255, 'UTF-8'),
                            range: $range,
                            headerRow: $headerRows > 0 ? $range->firstRow : null,
                            totalsRows: $totalsRows,
                        );
                    }

                    // Only the root element is of interest
                    break;
                }
            } finally {
                $xml->close();
            }
        }

        usort($tables, static fn (ReadTable $a, ReadTable $b): int => [$a->range->firstRow, $a->range->firstColumn] <=> [$b->range->firstRow, $b->range->firstColumn]);

        return $tables;
    }

    /**
     * The styles part: <numFmts> right under the root spells out the workbook's own formats, <cellXfs> lists the cell
     * styles a cell's "s" points into; each names a format by number. Nothing after </cellXfs> is read - the formats
     * of conditional formatting (<dxfs>) reuse the same numbers for other codes.
     *
     * @return list<NumberFormat>
     */
    private static function cellFormats(XlsxPackage $package, null|string $entry): array
    {
        if ($entry === null) {
            return [];
        }

        $codes = [];
        $formatIds = [];
        $inNumberFormats = false;
        $inCellFormats = false;
        $xml = $package->xml($entry);

        try {
            while ($xml->read()) {
                $type = $xml->reader->nodeType;
                $name = $xml->reader->localName;

                if ($type === \XMLReader::END_ELEMENT) {
                    if ($name === 'cellXfs' && $inCellFormats) {
                        break;
                    }

                    if ($name === 'numFmts') {
                        $inNumberFormats = false;
                    }

                    continue;
                }

                if ($type !== \XMLReader::ELEMENT) {
                    continue;
                }

                if ($name === 'numFmts' && $xml->reader->depth === 1) {
                    $inNumberFormats = $xml->reader->isEmptyElement === false;
                } elseif ($name === 'numFmt' && $inNumberFormats) {
                    if (count($codes) >= self::MAX_NUMBER_FORMATS) {
                        throw SpreadsheetUnreadable::tooLarge('the workbook defines too many number formats');
                    }

                    // Excel allows 255 characters; anything longer is cut, never refused
                    $codes[$xml->number('numFmtId', 0, 1_000_000)] = mb_strcut((string) $xml->attribute('formatCode'), 0, 1024, 'UTF-8');
                } elseif ($name === 'cellXfs' && $xml->reader->depth === 1) {
                    if ($xml->reader->isEmptyElement) {
                        break;
                    }

                    $inCellFormats = true;
                } elseif ($name === 'xf' && $inCellFormats) {
                    if (count($formatIds) >= self::MAX_CELL_STYLES) {
                        throw SpreadsheetUnreadable::tooLarge('the workbook defines too many cell styles');
                    }

                    $formatIds[] = $xml->number('numFmtId', 0, 1_000_000);
                }
            }
        } finally {
            $xml->close();
        }

        $general = NumberFormat::general();
        $byId = [];
        $formats = [];

        foreach ($formatIds as $formatId) {
            if (isset($byId[$formatId]) === false) {
                $code = $codes[$formatId] ?? NumberFormatClassifier::builtInCode($formatId);
                $byId[$formatId] = $code === null ? $general : NumberFormatClassifier::classify($code);
            }

            $formats[] = $byId[$formatId];
        }

        return $formats;
    }
}
