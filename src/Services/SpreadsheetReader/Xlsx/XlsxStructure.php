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
 * the date system, every cell style's number format (xl/styles.xml), and per sheet its used range, frozen pane and
 * Excel tables. The pass of our own that a streaming reader does not give (docs/features/data-import/README.md D14).
 */
final readonly class XlsxStructure
{
    /** More cell styles than Excel itself allows (64,000) is not a workbook anybody made */
    private const int MAX_CELL_STYLES = 70_000;
    private const int MAX_NUMBER_FORMATS = 10_000;
    private const int MAX_RELATIONSHIPS = 20_000;
    private const int MAX_TABLES = 2_000;
    /** Nodes of a small part (workbook.xml, a table's root, a sheet up to its first row) */
    private const int SMALL_PART_NODES = 200_000;

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
    ) {
    }

    public static function read(XlsxPackage $package, SpreadsheetLimits $limits): self
    {
        $relationships = self::relationships($package, XlsxPackage::WORKBOOK);
        $date1904 = false;
        $sheets = [];
        $entries = [];
        $titles = [];
        $tablesLeft = self::MAX_TABLES;

        $xml = $package->xml(XlsxPackage::WORKBOOK, self::SMALL_PART_NODES);

        try {
            while ($xml->read()) {
                if ($xml->reader->nodeType !== \XMLReader::ELEMENT) {
                    continue;
                }

                if ($xml->reader->localName === 'workbookPr') {
                    $date1904 = in_array(strtolower((string) $xml->attribute('date1904')), ['1', 'true', 'on'], true);

                    continue;
                }

                if ($xml->reader->localName !== 'sheet') {
                    continue;
                }

                $relationship = $relationships[(string) $xml->relationshipId()] ?? null;

                // A chart sheet, a macro sheet: tabs without cells
                if ($relationship === null || str_ends_with($relationship['type'], '/worksheet') === false) {
                    continue;
                }

                if (count($sheets) >= $limits->maxSheets) {
                    throw SpreadsheetUnreadable::tooManySheets($limits->maxSheets);
                }

                $title = (string) $xml->attribute('name');
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

        return new self($date1904, self::cellFormats($package, $relationships), $sheets, $entries);
    }

    /**
     * The relationships of a part.
     *
     * @return array<string, array{type: string, entry: string, target: string, external: bool}> relationship id => …
     */
    public static function relationships(XlsxPackage $package, string $entry): array
    {
        $relationshipsEntry = XlsxPackage::relationshipsOf($entry);

        if ($package->has($relationshipsEntry) === false) {
            return [];
        }

        $relationships = [];
        $xml = $package->xml($relationshipsEntry, self::MAX_RELATIONSHIPS * 4);

        try {
            while ($xml->read()) {
                if ($xml->reader->nodeType !== \XMLReader::ELEMENT || $xml->reader->localName !== 'Relationship') {
                    continue;
                }

                if (count($relationships) >= self::MAX_RELATIONSHIPS) {
                    break;
                }

                $target = (string) $xml->attribute('Target');
                $external = $xml->attribute('TargetMode') === 'External';

                $relationships[(string) $xml->attribute('Id')] = [
                    'type' => (string) $xml->attribute('Type'),
                    'entry' => $external ? '' : XlsxPackage::resolve($entry, $target),
                    'target' => $target,
                    'external' => $external,
                ];
            }
        } finally {
            $xml->close();
        }

        return $relationships;
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
        $xml = $package->xml($entry, self::SMALL_PART_NODES);

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
                    $usedRange = CellRange::fromA1((string) $xml->attribute('ref'));
                } elseif ($name === 'pane' && $frozenPane === null && in_array($xml->attribute('state'), ['frozen', 'frozenSplit'], true)) {
                    $rows = (int) (float) $xml->attribute('ySplit');
                    $columns = (int) (float) $xml->attribute('xSplit');

                    if ($rows >= 0 && $rows < CellRange::MAX_ROW && $columns >= 0 && $columns < CellRange::MAX_COLUMN && $rows + $columns > 0) {
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
     * The Excel tables of a sheet: its relationships name their parts, each part's root element says where it is.
     *
     * @return list<ReadTable> top to bottom, left to right
     */
    private static function tables(XlsxPackage $package, string $sheetEntry, int $atMost): array
    {
        $tables = [];

        foreach (self::relationships($package, $sheetEntry) as $relationship) {
            if (count($tables) >= $atMost) {
                break;
            }

            if (str_ends_with($relationship['type'], '/table') === false || $package->has($relationship['entry']) === false) {
                continue;
            }

            $xml = $package->xml($relationship['entry'], self::SMALL_PART_NODES);

            try {
                while ($xml->read()) {
                    if ($xml->reader->nodeType !== \XMLReader::ELEMENT) {
                        continue;
                    }

                    $range = $xml->reader->localName === 'table' ? CellRange::fromA1((string) $xml->attribute('ref')) : null;

                    if ($range !== null) {
                        $headerRows = $xml->attribute('headerRowCount') === null ? 1 : (int) $xml->attribute('headerRowCount');
                        $totalsRows = min(max(0, (int) $xml->attribute('totalsRowCount')), $range->rows() - 1);

                        $tables[] = new ReadTable(
                            name: (string) ($xml->attribute('displayName') ?? $xml->attribute('name')),
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
     * xl/styles.xml: <numFmts> spells out the workbook's own formats, <cellXfs> lists the cell styles a cell's "s"
     * points into; each names a format by number.
     *
     * @param array<string, array{type: string, entry: string, target: string, external: bool}> $relationships
     *
     * @return list<NumberFormat>
     */
    private static function cellFormats(XlsxPackage $package, array $relationships): array
    {
        $entry = null;

        foreach ($relationships as $relationship) {
            if (str_ends_with($relationship['type'], '/styles')) {
                $entry = $relationship['entry'];
            }
        }

        if ($entry === null || $package->has($entry) === false) {
            return [];
        }

        $codes = [];
        $formatIds = [];
        $inCellFormats = false;
        // Fonts, fills, borders and differential styles are walked through too - a styles part has no more of them
        // than cell styles times a handful
        $xml = $package->xml($entry, self::MAX_CELL_STYLES * 60);

        try {
            while ($xml->read()) {
                $type = $xml->reader->nodeType;
                $name = $xml->reader->localName;

                if ($type === \XMLReader::END_ELEMENT) {
                    if ($name === 'cellXfs') {
                        $inCellFormats = false;
                    }

                    continue;
                }

                if ($type !== \XMLReader::ELEMENT) {
                    continue;
                }

                if ($name === 'numFmt') {
                    if (count($codes) >= self::MAX_NUMBER_FORMATS) {
                        throw SpreadsheetUnreadable::tooLarge('the workbook defines too many number formats');
                    }

                    // Excel allows 255 characters; anything longer is cut, never refused
                    $codes[(int) $xml->attribute('numFmtId')] = substr((string) $xml->attribute('formatCode'), 0, 1024);
                } elseif ($name === 'cellXfs') {
                    $inCellFormats = $xml->reader->isEmptyElement === false;
                } elseif ($name === 'xf' && $inCellFormats) {
                    if (count($formatIds) >= self::MAX_CELL_STYLES) {
                        throw SpreadsheetUnreadable::tooLarge('the workbook defines too many cell styles');
                    }

                    $formatIds[] = (int) $xml->attribute('numFmtId');
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
