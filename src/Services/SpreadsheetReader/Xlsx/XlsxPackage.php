<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Services\SpreadsheetReader\Xlsx;

use SpeedPuzzling\Web\Exceptions\SpreadsheetReaderFailed;
use SpeedPuzzling\Web\Exceptions\SpreadsheetUnreadable;
use SpeedPuzzling\Web\Value\Spreadsheet\SpreadsheetLimits;

/**
 * The zip archive an .xlsx workbook is - and **the only door to anything inside it**
 * (docs/features/data-import/reader.md "Safety").
 *
 * When it is opened, before a byte is unpacked: the file's size, that it is a zip at all (an old .xls, a
 * password-protected workbook, an .ods, a .docx get their own reasons), the number of entries, no encrypted entry, no
 * entry name that leaves the archive or stands twice, and what the archive says it unpacks to (all parts together,
 * and the ratio to its packed size - a zip bomb).
 *
 * What the archive says proves nothing - libzip hands out whatever a compressed stream holds - so every part is read
 * through xml(), whatever its name and whoever points to it: the bytes it really unpacks to are counted as they are
 * read, against the size its header declares and against one budget for the whole workbook, and XmlPart refuses a
 * DOCTYPE, and XmlFloodScanner sees every chunk before the parser does. One ZipArchive, no stream wrapper, nothing
 * written to disk, and a part nobody reads (a picture) is never opened.
 */
final class XlsxPackage
{
    public const array RELATIONSHIP_NAMESPACES = [
        'http://schemas.openxmlformats.org/officeDocument/2006/relationships',
        'http://purl.oclc.org/ooxml/officeDocument/relationships',
    ];

    /** A part this small is not worth a ratio: a short repetitive XML packs well and harms nobody */
    private const int RATIO_FROM_BYTES = 1024 * 1024;

    private bool $closed = false;

    /**
     * @param array<string, array{int, int}> $entries entry name => [index in the archive, unpacked size it declares]
     */
    private function __construct(
        private readonly \ZipArchive $zip,
        private readonly array $entries,
        public readonly ReadBudget $budget,
        private readonly int $maxTextBytes,
    ) {
    }

    public static function open(string $path, SpreadsheetLimits $limits): self
    {
        // The upload is ours to keep: a file that is not there is the server's fault, not the player's file's
        if (is_file($path) === false || is_readable($path) === false) {
            throw new SpreadsheetReaderFailed('The spreadsheet file is not there or can not be opened.');
        }

        $size = (int) filesize($path);

        if ($size > $limits->maxFileBytes) {
            throw SpreadsheetUnreadable::tooLarge(sprintf('the file has %d bytes', $size));
        }

        $head = (string) file_get_contents($path, false, null, 0, 8);

        if ($head === "\xD0\xCF\x11\xE0\xA1\xB1\x1A\xE1") {
            throw self::holdsEncryptedPackage($path)
                ? SpreadsheetUnreadable::passwordProtected('the workbook is encrypted')
                : SpreadsheetUnreadable::unsupportedFormat('an old .xls workbook');
        }

        $zip = new \ZipArchive();

        if ($zip->open($path, \ZipArchive::RDONLY) !== true) {
            throw str_starts_with($head, "PK\x03\x04")
                ? SpreadsheetUnreadable::damaged('the zip archive is broken or cut short')
                : SpreadsheetUnreadable::notASpreadsheet('not a zip archive');
        }

        try {
            return new self(
                $zip,
                self::checkedEntries($zip, $size, $limits),
                new ReadBudget($limits->maxReadBytes, (int) round(max(0.0, $limits->maxReadSeconds) * 1e9)),
                $limits->maxCellBytes,
            );
        } catch (\Throwable $e) {
            $zip->close();

            throw $e;
        }
    }

    public function has(string $entry): bool
    {
        return isset($this->entries[$entry]);
    }

    /**
     * Opens a part for reading - any part, by its exact name.
     */
    public function xml(string $entry): XmlPart
    {
        $known = $this->entries[$entry] ?? null;

        if ($known === null || $this->closed) {
            throw SpreadsheetUnreadable::damaged(sprintf('the part "%s" is missing', $entry));
        }

        $stream = @$this->zip->getStreamIndex($known[0]);

        if ($stream === false) {
            throw SpreadsheetUnreadable::damaged(sprintf('the part "%s" can not be unpacked', $entry));
        }

        $meter = new PartMeter($known[1], $this->budget, new XmlFloodScanner($this->maxTextBytes));

        if (PartMeterFilter::register() === false || stream_filter_append($stream, PartMeterFilter::NAME, STREAM_FILTER_READ, $meter) === false) {
            fclose($stream);

            throw new SpreadsheetReaderFailed('The byte meter can not be put on a stream.');
        }

        return XmlPart::over($stream, $meter, $this->budget, $entry);
    }

    /**
     * What kind of archive this is when it turns out to hold no workbook.
     */
    public function notAWorkbook(): SpreadsheetUnreadable
    {
        return match (true) {
            $this->has('xl/workbook.bin') => SpreadsheetUnreadable::unsupportedFormat('a binary .xlsb workbook'),
            $this->has('mimetype') || $this->has('content.xml') => SpreadsheetUnreadable::unsupportedFormat('an OpenDocument file'),
            $this->has('Index/Document.iwa') || $this->has('Index.zip') => SpreadsheetUnreadable::unsupportedFormat('a Numbers file'),
            default => SpreadsheetUnreadable::notASpreadsheet('a zip archive without a workbook'),
        };
    }

    /**
     * Never throws.
     */
    public function close(): void
    {
        if ($this->closed === false) {
            $this->closed = true;
            @$this->zip->close();
        }
    }

    /**
     * The entry a relationship points to: "worksheets/sheet1.xml" next to xl/workbook.xml is xl/worksheets/sheet1.xml,
     * "/xl/worksheets/sheet1.xml" is itself, "../tables/table1.xml" climbs.
     */
    public static function resolve(string $sourceEntry, string $target): string
    {
        $path = str_starts_with($target, '/')
            ? $target
            : (str_contains($sourceEntry, '/') ? substr($sourceEntry, 0, (int) strrpos($sourceEntry, '/') + 1) : '') . $target;

        $parts = [];

        foreach (explode('/', $path) as $part) {
            if ($part === '..') {
                array_pop($parts);
            } elseif ($part !== '.' && $part !== '') {
                $parts[] = $part;
            }
        }

        return implode('/', $parts);
    }

    /**
     * The relationships part of a part: xl/worksheets/sheet1.xml → xl/worksheets/_rels/sheet1.xml.rels.
     */
    public static function relationshipsOf(string $entry): string
    {
        $slash = strrpos($entry, '/');

        return $slash === false
            ? '_rels/' . $entry . '.rels'
            : substr($entry, 0, $slash) . '/_rels/' . substr($entry, $slash + 1) . '.rels';
    }

    /**
     * @return array<string, array{int, int}>
     */
    private static function checkedEntries(\ZipArchive $zip, int $fileBytes, SpreadsheetLimits $limits): array
    {
        $count = $zip->count();

        if ($count > $limits->maxZipEntries) {
            throw SpreadsheetUnreadable::tooLarge(sprintf('the archive holds %d entries', $count));
        }

        $entries = [];
        $total = 0;

        for ($index = 0; $index < $count; $index++) {
            $stat = $zip->statIndex($index);

            if ($stat === false) {
                throw SpreadsheetUnreadable::damaged('a broken zip entry');
            }

            $name = $stat['name'];
            $unpacked = $stat['size'];

            if (
                str_contains($name, "\0") || str_contains($name, '\\') || str_starts_with($name, '/')
                || preg_match('~(^|/)\.\.(/|$)~', $name) === 1 || preg_match('~^[A-Za-z]:~', $name) === 1
            ) {
                throw SpreadsheetUnreadable::damaged('a zip entry with a name that leaves the archive');
            }

            // Two entries of one name: which one a reader gets depends on the reader - none of them here
            if (isset($entries[$name])) {
                throw SpreadsheetUnreadable::damaged('two zip entries of the same name');
            }

            if ($stat['encryption_method'] !== \ZipArchive::EM_NONE) {
                throw SpreadsheetUnreadable::passwordProtected('the archive holds an encrypted entry');
            }

            // A workbook's parts are stored or deflated: no other decoder (bzip2, lzma, xz, zstd) runs on an upload
            if ($stat['comp_method'] !== \ZipArchive::CM_STORE && $stat['comp_method'] !== \ZipArchive::CM_DEFLATE) {
                throw SpreadsheetUnreadable::unsupportedFormat('a zip entry packed by a method other than deflate');
            }

            if ($unpacked >= self::RATIO_FROM_BYTES && $unpacked > max(1, $stat['comp_size']) * $limits->maxCompressionRatio) {
                throw SpreadsheetUnreadable::tooLarge(sprintf('a part unpacks to %d× its size', intdiv($unpacked, max(1, $stat['comp_size']))));
            }

            $total += $unpacked;

            if ($total > $limits->maxUncompressedBytes) {
                throw SpreadsheetUnreadable::tooLarge(sprintf('the workbook unpacks to more than %d bytes', $limits->maxUncompressedBytes));
            }

            $entries[$name] = [$index, $unpacked];
        }

        if ($total >= self::RATIO_FROM_BYTES && $total > max(1, $fileBytes) * $limits->maxCompressionRatio) {
            throw SpreadsheetUnreadable::tooLarge(sprintf('the workbook unpacks to %d× its size', intdiv($total, max(1, $fileBytes))));
        }

        return $entries;
    }

    /**
     * A workbook saved with a password is not a zip but an old-style compound file holding an "EncryptedPackage".
     */
    private static function holdsEncryptedPackage(string $path): bool
    {
        $needle = mb_convert_encoding('EncryptedPackage', 'UTF-16LE', 'ASCII');
        $handle = fopen($path, 'rb');

        if ($handle === false) {
            return false;
        }

        try {
            $carry = '';

            while (feof($handle) === false) {
                $chunk = fread($handle, 1_048_576);

                if ($chunk === false) {
                    return false;
                }

                if (str_contains($carry . $chunk, $needle)) {
                    return true;
                }

                $carry = substr($chunk, -strlen($needle));
            }

            return false;
        } finally {
            fclose($handle);
        }
    }
}
