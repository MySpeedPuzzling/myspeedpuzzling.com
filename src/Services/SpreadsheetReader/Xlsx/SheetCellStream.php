<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Services\SpreadsheetReader\Xlsx;

use SpeedPuzzling\Web\Exceptions\SpreadsheetUnreadable;
use SpeedPuzzling\Web\Value\Spreadsheet\CellRange;
use SpeedPuzzling\Web\Value\Spreadsheet\SpreadsheetLimits;

/**
 * Our pass over a sheet's XML, row by row: for every cell that holds something its style index (→ number format),
 * its type, whether it is a formula, and its saved value exactly as stored - a number, a boolean, an error code, a
 * shared string's number, or the text itself (a text typed in place, a formula's text result).
 *
 * One row in memory at a time, node by node - nothing here asks libxml to look ahead of where the pass stands - and
 * every cap is checked where the bytes arrive: a value is refused the moment it grows over a cell's size, a row the
 * moment its values together do, a value beyond the last allowed column the moment its cell ends. A row or a cell
 * without its "r" attribute follows the previous one.
 *
 * With windows only the rows and columns inside their bounding box are collected: rows no window reaches into are
 * skipped by libxml alone (every chunk of them still passes the part's meter), the first row below the last window
 * ends the pass.
 */
final class SheetCellStream
{
    private const array TEXT_NODES = [
        \XMLReader::TEXT => true,
        \XMLReader::CDATA => true,
        \XMLReader::SIGNIFICANT_WHITESPACE => true,
        \XMLReader::WHITESPACE => true,
    ];

    private int $lastRow = 0;
    private bool $finished = false;
    /** The reader already stands on a node that was not looked at yet (after skipping a row) */
    private bool $standing = false;

    private readonly int $firstColumn;
    private readonly int $lastColumn;
    private readonly int $lastWindowRow;

    /**
     * @param list<CellRange> $windows
     */
    public function __construct(
        private readonly XmlPart $xml,
        private readonly SpreadsheetLimits $limits,
        private readonly array $windows = [],
    ) {
        $this->firstColumn = $windows === [] ? 1 : min(array_column($windows, 'firstColumn'));
        $this->lastColumn = $windows === [] ? CellRange::MAX_COLUMN : max(array_column($windows, 'lastColumn'));
        $this->lastWindowRow = $windows === [] ? CellRange::MAX_ROW : max(array_column($windows, 'lastRow'));
    }

    /**
     * The next <row> of the sheet (inside the window), empty ones included; null after the last.
     *
     * @return null|array{int, array<int, array{int, string, bool, string}>} the row number, and column (1 = A) =>
     *                                                                        [style index, type, formula?, raw value]
     *                                                                        of every cell that holds something
     */
    public function nextRow(): null|array
    {
        if ($this->finished) {
            return null;
        }

        $xml = $this->xml;
        $reader = $xml->reader;

        while ($this->standing || $xml->read()) {
            $this->standing = false;
            $type = $reader->nodeType;

            if ($type === \XMLReader::END_ELEMENT && $reader->localName === 'sheetData') {
                break;
            }

            if ($type !== \XMLReader::ELEMENT || $reader->localName !== 'row') {
                continue;
            }

            $row = $xml->number('r', $this->lastRow + 1, CellRange::MAX_ROW);

            if ($row < 1 || $row > CellRange::MAX_ROW) {
                throw SpreadsheetUnreadable::damaged('a row number no sheet has');
            }

            $this->lastRow = $row;

            if ($row > $this->lastWindowRow) {
                break;
            }

            if ($reader->isEmptyElement) {
                return [$row, []];
            }

            if ($this->windows !== [] && $this->inAWindow($row) === false) {
                // Past the whole row without a node of it reaching PHP
                if ($xml->next() === false) {
                    break;
                }

                $this->standing = true;

                continue;
            }

            return [$row, $this->cells()];
        }

        $this->finished = true;

        return null;
    }

    public function close(): void
    {
        $this->finished = true;
        $this->xml->close();
    }

    private function inAWindow(int $row): bool
    {
        foreach ($this->windows as $window) {
            if ($row >= $window->firstRow && $row <= $window->lastRow) {
                return true;
            }
        }

        return false;
    }

    /**
     * From a <row> to its end.
     *
     * @return array<int, array{int, string, bool, string}>
     */
    private function cells(): array
    {
        $xml = $this->xml;
        $reader = $xml->reader;
        $maxCellBytes = $this->limits->maxCellBytes;
        $firstColumn = $this->firstColumn;
        $lastColumn = $this->lastColumn;
        $cells = [];
        $rowBytes = 0;
        $previousColumn = 0;
        $column = 0;
        $style = 0;
        $type = 'n';
        $formula = false;
        $raw = '';
        $filled = false;
        // Inside a cell of the window that is not empty
        $inCell = false;
        // Inside a <v>, or inside a <t> of a text typed in place: its text nodes are the value
        $inText = false;
        $inInline = false;
        $inPhonetic = false;
        // The text of the <t> at hand, in a buffer of its own (a cell of a hundred thousand runs is not copied a
        // hundred thousand times); without xml:space="preserve" the white space around it goes, as Excel shows it
        $run = null;
        $runIsKept = false;

        while (true) {
            // The reader itself, without XmlPart's checks: they are about a document's prolog and end, and this is
            // the inside of a row - eight nodes a cell, a million cells
            if (@$reader->read() === false) {
                $xml->stoppedInside();
            }

            $nodeType = $reader->nodeType;

            if ($inText) {
                if ($nodeType === \XMLReader::END_ELEMENT) {
                    $inText = false;

                    if ($run !== null) {
                        $raw .= $runIsKept ? $run : trim($run, " \t\n\r");
                        $run = null;
                    }
                } elseif (isset(self::TEXT_NODES[$nodeType])) {
                    if ($run === null) {
                        $raw .= $reader->value;
                    } else {
                        $run .= $reader->value;
                    }

                    if (strlen($raw) + strlen((string) $run) > $maxCellBytes) {
                        throw SpreadsheetUnreadable::cellTooLarge(sprintf('a cell of more than %d bytes', $maxCellBytes));
                    }
                }

                continue;
            }

            if ($nodeType === \XMLReader::END_ELEMENT) {
                $name = $reader->localName;

                if ($name === 'c') {
                    if ($filled) {
                        if ($column > $this->limits->maxColumns) {
                            throw SpreadsheetUnreadable::tooManyColumns($this->limits->maxColumns);
                        }

                        $rowBytes += strlen($raw);

                        if ($rowBytes > $this->limits->maxRowBytes) {
                            throw SpreadsheetUnreadable::cellTooLarge(sprintf('a row of more than %d bytes', $this->limits->maxRowBytes));
                        }

                        $cells[$column] = [$style, $type, $formula, $raw];
                    }

                    $inCell = false;
                    $inInline = false;
                    $inPhonetic = false;
                } elseif ($name === 'row') {
                    return $cells;
                } elseif ($name === 'rPh') {
                    $inPhonetic = false;
                } elseif ($name === 'is') {
                    $inInline = false;
                }

                continue;
            }

            if ($nodeType !== \XMLReader::ELEMENT) {
                continue;
            }

            $name = $reader->localName;

            if ($name === 'c') {
                $reference = $reader->getAttribute('r');

                if ($reference === null) {
                    $column = $previousColumn + 1;
                } elseif (strlen($reference) <= 10 && preg_match('/^([A-Z]{1,3})\d+$/', $reference, $match) === 1) {
                    $column = CellRange::columnNumber($match[1]);
                } else {
                    throw SpreadsheetUnreadable::damaged('a cell reference no sheet has');
                }

                if ($column <= $previousColumn || $column > CellRange::MAX_COLUMN) {
                    throw SpreadsheetUnreadable::damaged('cells of a row out of order');
                }

                $previousColumn = $column;
                $filled = false;
                $inCell = $reader->isEmptyElement === false && $column >= $firstColumn && $column <= $lastColumn;

                if ($inCell) {
                    $style = $xml->number('s', 0, 1_000_000);
                    $type = $reader->getAttribute('t') ?? 'n';
                    $formula = false;
                    $raw = '';

                    if (strlen($type) > 16) {
                        throw SpreadsheetUnreadable::damaged('a cell of a kind no sheet has');
                    }
                }
            } elseif ($inCell === false) {
                continue;
            } elseif ($inInline) {
                // A text typed in place: <is><t>…</t></is>, or runs of rich text <is><r><t>…</t></r>…</is>. The
                // reading aid of East Asian text (<rPh>) is not part of the text.
                if ($name === 'rPh') {
                    $inPhonetic = $reader->isEmptyElement === false;
                } elseif ($name === 't' && $inPhonetic === false && $reader->isEmptyElement === false) {
                    $inText = true;
                    $run = '';
                    $runIsKept = $reader->getAttribute('xml:space') === 'preserve';
                }
            } elseif ($name === 'v') {
                // A cell of inline text has no <v> of interest; any other cell's value is the <v>
                if ($type !== 'inlineStr') {
                    $raw = '';
                    $run = null;
                    $filled = true;
                    $inText = $reader->isEmptyElement === false;
                }
            } elseif ($name === 'f') {
                $formula = true;
            } elseif ($name === 'is' && $type === 'inlineStr') {
                $filled = true;
                $inInline = $reader->isEmptyElement === false;
            }
        }
    }
}
