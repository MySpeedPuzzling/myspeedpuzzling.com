<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Services\Export;

use DOMDocument;
use DOMElement;
use LogicException;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use SpeedPuzzling\Web\Results\Export\ExportDocument;
use SpeedPuzzling\Web\Results\Export\ExportFile;
use SpeedPuzzling\Web\Results\Export\ExportSection;
use SpeedPuzzling\Web\Value\ExportFormat;
use ZipArchive;

/**
 * Renders a set of flat sections into one file: an XLSX sheet, a JSON key, a CSV file in a ZIP and an XML element
 * per section, named the same in every format (docs/features/data-export.md). Built in memory - the largest
 * libraries are a few thousand rows.
 */
readonly final class SectionedExportWriter
{
    public function write(ExportDocument $document, ExportFormat $format): ExportFile
    {
        $rootName = $document->rootName;
        $about = $document->about;
        $sections = $document->sections;

        return match ($format) {
            ExportFormat::Json => new ExportFile($this->toJson($about, $sections), $format->contentType(), 'json'),
            ExportFormat::Xlsx => new ExportFile($this->toXlsx($about, $sections), $format->contentType(), 'xlsx'),
            ExportFormat::Csv => new ExportFile($this->toZipOfCsv($rootName, $about, $sections), 'application/zip', 'zip'),
            ExportFormat::Xml => new ExportFile($this->toXml($rootName, $about, $sections), $format->contentType(), 'xml'),
        };
    }

    /**
     * @param array<string, scalar|null> $about
     * @param list<ExportSection> $sections
     */
    private function toJson(array $about, array $sections): string
    {
        $summary = [];

        foreach ($sections as $section) {
            $summary[$section->name] = ['rows' => count($section->rows), 'description' => $section->description];
        }

        $document = ['export' => [...$about, 'sections' => $summary]];

        foreach ($sections as $section) {
            $document[$section->name] = array_map(
                fn (array $row): array => $this->orderedRow($section, $row),
                $section->rows,
            );
        }

        return json_encode($document, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    }

    /**
     * @param array<string, scalar|null> $about
     * @param list<ExportSection> $sections
     */
    private function toXlsx(array $about, array $sections): string
    {
        $spreadsheet = new Spreadsheet();

        $aboutSheet = $spreadsheet->getActiveSheet();
        $aboutSheet->setTitle('about');
        $this->writeHeader($aboutSheet, ['key', 'value']);

        $row = 2;
        foreach ($about as $key => $value) {
            SpreadsheetSafeValue::writeXlsxCell($aboutSheet, 1, $row, $key);
            SpreadsheetSafeValue::writeXlsxCell($aboutSheet, 2, $row, $value);
            $row++;
        }

        $row++;
        $this->writeHeader($aboutSheet, ['section', 'rows', 'description'], $row);
        foreach ($sections as $section) {
            $row++;
            SpreadsheetSafeValue::writeXlsxCell($aboutSheet, 1, $row, $section->name);
            SpreadsheetSafeValue::writeXlsxCell($aboutSheet, 2, $row, count($section->rows));
            SpreadsheetSafeValue::writeXlsxCell($aboutSheet, 3, $row, $section->description);
        }

        foreach ($sections as $section) {
            $sheet = $spreadsheet->createSheet();
            $sheet->setTitle($section->name);
            $this->writeHeader($sheet, $section->columns);
            $sheet->freezePane('A2');

            $row = 2;
            foreach ($section->rows as $values) {
                $column = 1;
                foreach ($this->orderedRow($section, $values) as $value) {
                    SpreadsheetSafeValue::writeXlsxCell($sheet, $column, $row, $value);
                    $column++;
                }
                $row++;
            }
        }

        $spreadsheet->setActiveSheetIndex(0);

        $tempFile = tempnam(sys_get_temp_dir(), 'export_');
        assert(is_string($tempFile));

        try {
            (new Xlsx($spreadsheet))->save($tempFile);
            $content = file_get_contents($tempFile);
        } finally {
            unlink($tempFile);
        }

        return $content !== false ? $content : '';
    }

    /**
     * @param array<string, scalar|null> $about
     * @param list<ExportSection> $sections
     */
    private function toZipOfCsv(string $rootName, array $about, array $sections): string
    {
        $tempFile = tempnam(sys_get_temp_dir(), 'export_');
        assert(is_string($tempFile));

        try {
            $zip = new ZipArchive();
            if ($zip->open($tempFile, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
                throw new LogicException('Could not create the export ZIP.');
            }

            $aboutRows = [['key', 'value']];
            foreach ($about as $key => $value) {
                $aboutRows[] = [$key, $value];
            }

            $zip->addFromString('README.txt', $this->readme($rootName, $sections));
            $zip->addFromString('about.csv', $this->csv($aboutRows));

            foreach ($sections as $section) {
                $rows = [$section->columns];
                foreach ($section->rows as $values) {
                    $rows[] = array_values($this->orderedRow($section, $values));
                }

                $zip->addFromString($section->name . '.csv', $this->csv($rows));
            }

            $zip->close();
            $content = file_get_contents($tempFile);
        } finally {
            unlink($tempFile);
        }

        return $content !== false ? $content : '';
    }

    /**
     * @param array<string, scalar|null> $about
     * @param list<ExportSection> $sections
     */
    private function toXml(string $rootName, array $about, array $sections): string
    {
        $dom = new DOMDocument('1.0', 'UTF-8');
        $dom->formatOutput = true;

        $root = $dom->createElement($rootName);
        $dom->appendChild($root);

        $aboutElement = $dom->createElement('about');
        $root->appendChild($aboutElement);
        foreach ($about as $key => $value) {
            $this->appendValue($dom, $aboutElement, $key, $value);
        }

        foreach ($sections as $section) {
            $sectionElement = $dom->createElement($section->name);
            $sectionElement->setAttribute('rows', (string) count($section->rows));
            $sectionElement->setAttribute('description', $section->description);
            $root->appendChild($sectionElement);

            foreach ($section->rows as $values) {
                $record = $dom->createElement('record');
                $sectionElement->appendChild($record);

                foreach ($this->orderedRow($section, $values) as $column => $value) {
                    $this->appendValue($dom, $record, $column, $value);
                }
            }
        }

        $xml = $dom->saveXML();

        return $xml !== false ? $xml : '';
    }

    private function appendValue(DOMDocument $dom, DOMElement $parent, string $name, null|bool|int|float|string $value): void
    {
        $element = $dom->createElement($name);
        $element->appendChild($dom->createTextNode(match (true) {
            $value === null => '',
            is_bool($value) => $value ? 'true' : 'false',
            default => (string) $value,
        }));
        $parent->appendChild($element);
    }

    /**
     * @param array<string, scalar|null> $row
     * @return array<string, scalar|null> the row's values in the section's column order
     */
    private function orderedRow(ExportSection $section, array $row): array
    {
        $ordered = [];

        foreach ($section->columns as $column) {
            if (array_key_exists($column, $row) === false) {
                throw new LogicException(sprintf('Export section "%s" has a row without the column "%s".', $section->name, $column));
            }

            $ordered[$column] = $row[$column];
        }

        return $ordered;
    }

    /**
     * @param list<string> $columns
     */
    private function writeHeader(Worksheet $sheet, array $columns, int $row = 1): void
    {
        foreach ($columns as $index => $column) {
            $sheet->setCellValueExplicit([$index + 1, $row], $column, DataType::TYPE_STRING);
        }

        $sheet->getStyle([1, $row, max(1, count($columns)), $row])->getFont()->setBold(true);
    }

    /**
     * @param list<list<scalar|null>> $rows
     */
    private function csv(array $rows): string
    {
        $handle = fopen('php://temp', 'r+');
        assert($handle !== false);

        foreach ($rows as $row) {
            fputcsv($handle, array_map(SpreadsheetSafeValue::forCsv(...), $row), ',', '"', '');
        }

        rewind($handle);
        $content = stream_get_contents($handle);
        fclose($handle);

        return $content !== false ? $content : '';
    }

    /**
     * @param list<ExportSection> $sections
     */
    private function readme(string $rootName, array $sections): string
    {
        $lines = [
            sprintf('MySpeedPuzzling export: %s', $rootName),
            '',
            'Every CSV file is UTF-8, comma separated, with a header row. Column names and values are in English;',
            'only the names of the built-in lists (like your main puzzle collection) are in the language you exported in.',
            'A text value starting with = + - @ gets a leading apostrophe so a spreadsheet never runs it as a formula.',
            '',
            'about.csv - when the export was made, who it belongs to and your list settings.',
        ];

        foreach ($sections as $section) {
            $lines[] = sprintf('%s.csv (%d rows) - %s', $section->name, count($section->rows), $section->description);
        }

        return implode("\n", $lines) . "\n";
    }
}
