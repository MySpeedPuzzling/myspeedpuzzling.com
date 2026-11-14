<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Services\SpreadsheetReader\Xlsx;

use SpeedPuzzling\Web\Exceptions\SpreadsheetUnreadable;
use SpeedPuzzling\Web\Services\SpreadsheetReader\TemporaryFolder;
use SpeedPuzzling\Web\Value\Spreadsheet\ReadCell;
use SpeedPuzzling\Web\Value\Spreadsheet\SpreadsheetLimits;

/**
 * The shared strings of a workbook - every distinct text of every sheet, kept once in a part of their own and pointed
 * to by number from the cells. Read when the first cell asks for one (a sheet of numbers never pays for them), in one
 * pass of our own, node by node: nothing is built in memory but the string at hand.
 *
 * - A string is its <t> texts put together: plain (<si><t>) or runs of rich text (<si><r><t>). The reading aid of
 *   East Asian text (<rPh>) is not part of it.
 * - A <t> without xml:space="preserve" loses the white space around it, as Excel shows it.
 * - Bounded: a string by SpreadsheetLimits::$maxCellBytes, their number by ::$maxSharedStrings and their bytes
 *   together by ::$maxSharedStringBytes (fixed caps: every job that reads a sheet with texts reads them all, whatever
 *   is left of the import's cell budget), the part's bytes and the time by the workbook's budget - and a string of
 *   two million empty runs is two million steps, no more.
 */
final class SharedStrings
{
    private null|SharedStringsStore $store = null;

    public function __construct(
        private readonly XlsxPackage $package,
        private readonly null|string $entry,
        private readonly TemporaryFolder $folder,
        private readonly SpreadsheetLimits $limits,
    ) {
    }

    public function get(int $number): string
    {
        $this->store ??= $this->read();

        return $this->store->get($number) ?? throw SpreadsheetUnreadable::damaged('a cell points to a shared string that is not there');
    }

    /**
     * Never throws.
     */
    public function clear(): void
    {
        $this->store?->clear();
        $this->store = null;
    }

    private function read(): SharedStringsStore
    {
        if ($this->entry === null) {
            throw SpreadsheetUnreadable::damaged('a cell points to shared strings the workbook does not have');
        }

        $store = new SharedStringsStore($this->folder);
        $xml = $this->package->xml($this->entry);
        $reader = $xml->reader;
        $maxBytes = $this->limits->maxCellBytes;
        $inString = false;
        $inPhonetic = false;
        $inText = false;
        $preserve = false;
        $string = '';
        $text = '';
        $bytes = 0;

        try {
            while ($xml->read()) {
                $type = $reader->nodeType;

                if ($inText) {
                    if ($type === \XMLReader::END_ELEMENT) {
                        $inText = false;
                        $string .= $preserve ? $text : trim($text, " \t\n\r");
                    } elseif (
                        $type === \XMLReader::TEXT || $type === \XMLReader::CDATA
                        || $type === \XMLReader::SIGNIFICANT_WHITESPACE || $type === \XMLReader::WHITESPACE
                    ) {
                        $text .= $reader->value;

                        if (strlen($string) + strlen($text) > $maxBytes) {
                            throw SpreadsheetUnreadable::cellTooLarge(sprintf('a shared string of more than %d bytes', $maxBytes));
                        }
                    }

                    continue;
                }

                if ($type === \XMLReader::END_ELEMENT) {
                    $name = $reader->localName;

                    if ($name === 'si' && $inString) {
                        $inString = false;
                        $inPhonetic = false;
                        $bytes += strlen($string);

                        if ($bytes > $this->limits->maxSharedStringBytes) {
                            throw SpreadsheetUnreadable::tooLarge(sprintf('the workbook holds more than %d bytes of distinct texts', $this->limits->maxSharedStringBytes));
                        }

                        $store->add(self::decoded($string));
                    } elseif ($name === 'rPh') {
                        $inPhonetic = false;
                    }

                    continue;
                }

                if ($type !== \XMLReader::ELEMENT) {
                    continue;
                }

                $name = $reader->localName;

                if ($name === 'si') {
                    if ($store->count() >= $this->limits->maxSharedStrings) {
                        throw SpreadsheetUnreadable::tooLarge(sprintf('the workbook holds more than %d distinct texts', $this->limits->maxSharedStrings));
                    }

                    $string = '';
                    $inPhonetic = false;
                    $inString = $reader->isEmptyElement === false;

                    if ($inString === false) {
                        $store->add('');
                    }
                } elseif ($inString === false) {
                    continue;
                } elseif ($name === 'rPh') {
                    $inPhonetic = $reader->isEmptyElement === false;
                } elseif ($name === 't' && $inPhonetic === false && $reader->isEmptyElement === false) {
                    $inText = true;
                    $text = '';
                    $preserve = $reader->getAttribute('xml:space') === 'preserve';
                }
            }

            $store->finish();
        } catch (\Throwable $e) {
            $store->clear();

            throw $e;
        } finally {
            $xml->close();
        }

        return $store;
    }

    /**
     * A text as the player typed it. A spreadsheet writes a character XML can not hold as _xHHHH_ (a carriage return
     * inside a cell is _x000D_, an underscore in front of such a sequence _x005F_); control characters that are no
     * text are dropped (ReadCell::withoutControls()).
     */
    public static function decoded(string $text): string
    {
        if (str_contains($text, '_x')) {
            $text = preg_replace_callback(
                '/_x([0-9A-Fa-f]{4})_/',
                static fn (array $match): string => (string) mb_chr((int) hexdec($match[1]), 'UTF-8'),
                $text,
            ) ?? $text;
        }

        return ReadCell::withoutControls($text);
    }
}
