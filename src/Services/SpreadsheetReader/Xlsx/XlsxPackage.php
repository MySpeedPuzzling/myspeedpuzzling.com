<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Services\SpreadsheetReader\Xlsx;

use SpeedPuzzling\Web\Exceptions\SpreadsheetUnreadable;
use SpeedPuzzling\Web\Value\Spreadsheet\SpreadsheetLimits;

/**
 * The zip archive an .xlsx workbook is, checked before a byte of it is parsed
 * (docs/features/data-import/reader.md "Safety"):
 *
 * - the file's size, that it is a zip at all (an old .xls, a password-protected workbook, an .ods, a .docx get their
 *   own reasons), the number of entries, no encrypted entry, no entry name that leaves the archive;
 * - what the archive says it unpacks to: all parts together, and the ratio to its packed size (a zip bomb);
 * - that it tells the truth: every XML part of the workbook is unpacked once, counted, and refused when it turns out
 *   longer than its header says - libzip hands out whatever the stream holds, so the declared sizes alone prove nothing;
 * - and that no such part declares a document type (where XML entities - the stuff of XML attacks - come from).
 *
 * Nothing is ever written to disk here, and pictures or other binary parts are never opened.
 */
final readonly class XlsxPackage
{
    public const array RELATIONSHIP_NAMESPACES = [
        'http://schemas.openxmlformats.org/officeDocument/2006/relationships',
        'http://purl.oclc.org/ooxml/officeDocument/relationships',
    ];

    public const string WORKBOOK = 'xl/workbook.xml';
    public const string WORKBOOK_RELATIONSHIPS = 'xl/_rels/workbook.xml.rels';

    /** A part this small is not worth a ratio: a short repetitive XML packs well and harms nobody */
    private const int RATIO_FROM_BYTES = 1024 * 1024;

    /**
     * @param array<string, int> $entries entry name => unpacked size
     */
    private function __construct(
        public string $path,
        private array $entries,
    ) {
    }

    public static function open(string $path, SpreadsheetLimits $limits): self
    {
        $realPath = is_file($path) && is_readable($path) ? realpath($path) : false;

        if ($realPath === false) {
            throw SpreadsheetUnreadable::damaged('the file can not be opened');
        }

        if (str_contains($realPath, '#')) {
            throw new \InvalidArgumentException('A workbook can not be read from a path with "#" in it.');
        }

        $size = (int) filesize($realPath);

        if ($size > $limits->maxFileBytes) {
            throw SpreadsheetUnreadable::tooLarge(sprintf('the file has %d bytes', $size));
        }

        $head = (string) file_get_contents($realPath, false, null, 0, 8);

        if ($head === "\xD0\xCF\x11\xE0\xA1\xB1\x1A\xE1") {
            throw self::holdsEncryptedPackage($realPath)
                ? SpreadsheetUnreadable::passwordProtected('the workbook is encrypted')
                : SpreadsheetUnreadable::unsupportedFormat('an old .xls workbook');
        }

        $zip = new \ZipArchive();

        if ($zip->open($realPath, \ZipArchive::RDONLY) !== true) {
            throw str_starts_with($head, "PK\x03\x04")
                ? SpreadsheetUnreadable::damaged('the zip archive is broken or cut short')
                : SpreadsheetUnreadable::notASpreadsheet('not a zip archive');
        }

        try {
            $entries = self::checkedEntries($zip, $size, $limits);
            self::assertIsWorkbook($entries);
            self::assertSizesAreTrue($zip, $entries);
        } finally {
            $zip->close();
        }

        self::assertNoDocumentTypes($realPath, $entries);

        return new self($realPath, $entries);
    }

    public function has(string $entry): bool
    {
        return isset($this->entries[$entry]);
    }

    /**
     * @param int $maxNodes how many XML nodes the caller is ready to walk through
     */
    public function xml(string $entry, int $maxNodes): XmlPart
    {
        if ($this->has($entry) === false) {
            throw SpreadsheetUnreadable::damaged(sprintf('the part "%s" is missing', $entry));
        }

        return XmlPart::open($this->path, $entry, $maxNodes);
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
     * @return array<string, int>
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

            if ($stat['encryption_method'] !== \ZipArchive::EM_NONE) {
                throw SpreadsheetUnreadable::passwordProtected('the archive holds an encrypted entry');
            }

            if ($unpacked >= self::RATIO_FROM_BYTES && $unpacked > max(1, $stat['comp_size']) * $limits->maxCompressionRatio) {
                throw SpreadsheetUnreadable::tooLarge(sprintf('the part "%s" unpacks to %d× its size', $name, intdiv($unpacked, max(1, $stat['comp_size']))));
            }

            $total += $unpacked;

            if ($total > $limits->maxUncompressedBytes) {
                throw SpreadsheetUnreadable::tooLarge(sprintf('the workbook unpacks to more than %d bytes', $limits->maxUncompressedBytes));
            }

            $entries[$name] = $unpacked;
        }

        if ($total >= self::RATIO_FROM_BYTES && $total > max(1, $fileBytes) * $limits->maxCompressionRatio) {
            throw SpreadsheetUnreadable::tooLarge(sprintf('the workbook unpacks to %d× its size', intdiv($total, max(1, $fileBytes))));
        }

        return $entries;
    }

    /**
     * @param array<string, int> $entries
     */
    private static function assertIsWorkbook(array $entries): void
    {
        if (isset($entries[self::WORKBOOK], $entries[self::WORKBOOK_RELATIONSHIPS])) {
            return;
        }

        throw match (true) {
            isset($entries['xl/workbook.bin']) => SpreadsheetUnreadable::unsupportedFormat('a binary .xlsb workbook'),
            isset($entries['mimetype']) || isset($entries['content.xml']) => SpreadsheetUnreadable::unsupportedFormat('an OpenDocument file'),
            isset($entries['Index/Document.iwa']) || isset($entries['Index.zip']) => SpreadsheetUnreadable::unsupportedFormat('a Numbers file'),
            isset($entries[self::WORKBOOK]) => SpreadsheetUnreadable::damaged('the workbook has no relationships part'),
            default => SpreadsheetUnreadable::notASpreadsheet('a zip archive without a workbook'),
        };
    }

    /**
     * @param array<string, int> $entries
     */
    private static function assertSizesAreTrue(\ZipArchive $zip, array $entries): void
    {
        foreach ($entries as $name => $declared) {
            if (self::isRead($name) === false) {
                continue;
            }

            $stream = @$zip->getStream($name);

            if ($stream === false) {
                throw SpreadsheetUnreadable::damaged(sprintf('the part "%s" can not be unpacked', $name));
            }

            try {
                $read = 0;

                while (feof($stream) === false) {
                    $chunk = fread($stream, 262_144);

                    if ($chunk === false) {
                        throw SpreadsheetUnreadable::damaged(sprintf('the part "%s" can not be unpacked', $name));
                    }

                    $read += strlen($chunk);

                    if ($read > $declared) {
                        throw SpreadsheetUnreadable::damaged(sprintf('the part "%s" is longer than the archive says', $name));
                    }
                }
            } finally {
                fclose($stream);
            }

            if ($read !== $declared) {
                throw SpreadsheetUnreadable::damaged(sprintf('the part "%s" is shorter than the archive says', $name));
            }
        }
    }

    /**
     * A workbook part never has a DOCTYPE - and without one no entity can be declared, so none can be expanded or
     * fetched by whoever parses the part later (OpenSpout reads the workbook and the shared strings by itself). A
     * DOCTYPE can only stand before the root element, so every part is opened and read up to there.
     *
     * @param array<string, int> $entries
     */
    private static function assertNoDocumentTypes(string $path, array $entries): void
    {
        foreach ($entries as $name => $size) {
            if (self::isRead($name) === false) {
                continue;
            }

            $xml = XmlPart::open($path, $name, 10_000);

            try {
                // XmlPart::read() refuses a document type declaration
                while ($xml->read()) {
                    if ($xml->reader->nodeType === \XMLReader::ELEMENT) {
                        break;
                    }
                }
            } finally {
                $xml->close();
            }
        }
    }

    /**
     * The parts somebody parses: the workbook's own XML. Pictures, printer settings, macros and custom XML are never
     * opened, so what they hold does not matter.
     */
    private static function isRead(string $name): bool
    {
        return (str_starts_with($name, 'xl/') || $name === '_rels/.rels' || $name === '[Content_Types].xml')
            && (str_ends_with($name, '.xml') || str_ends_with($name, '.rels'));
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
