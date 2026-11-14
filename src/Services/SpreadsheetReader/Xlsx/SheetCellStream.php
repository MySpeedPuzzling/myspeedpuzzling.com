<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Services\SpreadsheetReader\Xlsx;

use SpeedPuzzling\Web\Exceptions\SpreadsheetUnreadable;
use SpeedPuzzling\Web\Value\Spreadsheet\CellRange;

/**
 * Our own pass over a sheet's XML, row by row: for every cell that holds something its style index (→ number format),
 * its type, whether it is a formula, and its saved value exactly as stored - a number, a boolean, an error code, a
 * shared string's index, or the text itself (a text typed in place, a formula's text result).
 *
 * One row in memory at a time, node by node - nothing here asks libxml to look ahead of where the pass stands. A row or
 * a cell without its "r" attribute follows the previous one.
 */
final class SheetCellStream
{
    private int $lastRow = 0;
    private bool $finished = false;

    public function __construct(
        private readonly XmlPart $xml,
    ) {
    }

    /**
     * The next <row> of the sheet, empty ones included; null after the last.
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

        while ($xml->read()) {
            if ($reader->nodeType !== \XMLReader::ELEMENT || $reader->localName !== 'row') {
                continue;
            }

            $number = $reader->getAttribute('r');
            $row = $number === null ? $this->lastRow + 1 : (int) $number;

            if ($row < 1 || $row > CellRange::MAX_ROW) {
                throw SpreadsheetUnreadable::damaged('a row number no sheet has');
            }

            $this->lastRow = $row;

            return [$row, $reader->isEmptyElement ? [] : $this->cells()];
        }

        $this->finished = true;

        return null;
    }

    public function close(): void
    {
        $this->finished = true;
        $this->xml->close();
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
        $cells = [];
        $lastColumn = 0;
        $column = 0;
        $style = 0;
        $type = 'n';
        $formula = false;
        $raw = '';
        $filled = false;
        $inCell = false;
        // Inside a <v>, or inside a <t> of a text typed in place: its text nodes are the value
        $inText = false;
        $inInline = false;
        $inPhonetic = false;

        while ($xml->read()) {
            $nodeType = $reader->nodeType;

            if ($inText) {
                if ($nodeType === \XMLReader::END_ELEMENT) {
                    $inText = false;
                } elseif (
                    $nodeType === \XMLReader::TEXT || $nodeType === \XMLReader::CDATA
                    || $nodeType === \XMLReader::SIGNIFICANT_WHITESPACE || $nodeType === \XMLReader::WHITESPACE
                ) {
                    $raw .= $reader->value;
                }

                continue;
            }

            if ($nodeType === \XMLReader::END_ELEMENT) {
                $name = $reader->localName;

                if ($name === 'c') {
                    if ($filled) {
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
                    $column = $lastColumn + 1;
                } elseif (preg_match('/^([A-Z]{1,3})\d+$/', $reference, $match) === 1) {
                    $column = CellRange::columnNumber($match[1]);
                } else {
                    throw SpreadsheetUnreadable::damaged('a cell reference no sheet has');
                }

                if ($column <= $lastColumn || $column > CellRange::MAX_COLUMN) {
                    throw SpreadsheetUnreadable::damaged('cells of a row out of order');
                }

                $lastColumn = $column;
                $style = (int) $reader->getAttribute('s');
                $type = $reader->getAttribute('t') ?? 'n';
                $formula = false;
                $raw = '';
                $filled = false;
                $inCell = $reader->isEmptyElement === false;
            } elseif ($inCell === false) {
                continue;
            } elseif ($inInline) {
                // A text typed in place: <is><t>…</t></is>, or runs of rich text <is><r><t>…</t></r>…</is>. The
                // reading aid of East Asian text (<rPh>) is not part of the text.
                if ($name === 'rPh') {
                    $inPhonetic = $reader->isEmptyElement === false;
                } elseif ($name === 't' && $inPhonetic === false) {
                    $inText = $reader->isEmptyElement === false;
                }
            } elseif ($name === 'v') {
                // A cell of inline text has no <v> of interest; any other cell's value is the <v>
                if ($type !== 'inlineStr') {
                    $raw = '';
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

        // The part ended inside a row: XmlPart::read() has already refused a document cut short
        return $cells;
    }
}
