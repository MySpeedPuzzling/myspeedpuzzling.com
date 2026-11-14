<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Services;

use DOMDocument;
use DOMElement;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Csv;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use SimpleXMLElement;
use SpeedPuzzling\Web\Results\Export\OwnCustomColumns;
use SpeedPuzzling\Web\Results\ExportableSolvingTime;
use SpeedPuzzling\Web\Services\Export\SpreadsheetSafeValue;
use SpeedPuzzling\Web\Value\ExportFormat;

readonly final class PuzzlerDataExporter
{
    private const array HEADERS = [
        'result_id',
        'puzzle_id',
        'puzzle_name',
        'brand_name',
        'pieces_count',
        'seconds_to_solve',
        'time_formatted',
        'finished_at',
        'tracked_at',
        'type',
        'first_attempt',
        'unboxed',
        'players_count',
        'team_members',
        'finished_puzzle_photo_url',
        'comment',
        'puzzle_fastest_time',
        'puzzle_fastest_time_formatted',
        'puzzle_average_time',
        'puzzle_average_time_formatted',
        'player_rank',
        'puzzle_total_solved',
        // new columns go at the end: people's scripts read the CSV by column position
        'ppm',
        // the result's event (docs/features/events-page/high-frequency-series.md P22)
        'event_id',
        'event_name',
        'event_series_id',
        'event_series_name',
        // whether the result counts toward the MSP rating, and if not why (ResultRatingStatus)
        'msp_rating',
        'msp_rating_reason',
        'msp_rating_puzzle_first_try_solvers',
    ];

    /**
     * The player's own fields (docs/features/data-export.md "Own fields") come after every other column, and only
     * for a player who has any - without them the file is what it always was.
     *
     * @param array<ExportableSolvingTime> $data
     * @param null|OwnCustomColumns $custom the downloading player's own values, keyed by result id
     */
    public function export(array $data, ExportFormat $format, null|OwnCustomColumns $custom = null): string
    {
        $custom ??= new OwnCustomColumns();

        return match ($format) {
            ExportFormat::Json => $this->toJson($data, $custom),
            ExportFormat::Xlsx => $this->toXlsx($data, $custom),
            ExportFormat::Csv => $this->toCsv($data, $custom),
            ExportFormat::Xml => $this->toXml($data, $custom),
        };
    }

    /**
     * @param array<ExportableSolvingTime> $data
     */
    private function toJson(array $data, OwnCustomColumns $custom): string
    {
        $rows = array_map(
            static fn(ExportableSolvingTime $item): array => $custom->isEmpty()
                ? $item->toArray()
                // An object also when every field is empty - never a list
                : [...$item->toArray(), OwnCustomColumns::ELEMENT => (object) $custom->of($item->timeId)],
            $data,
        );

        return json_encode($rows, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    }

    /**
     * @param array<ExportableSolvingTime> $data
     */
    private function toXlsx(array $data, OwnCustomColumns $custom): string
    {
        $spreadsheet = $this->createSpreadsheet($data, $custom, forCsv: false);

        $writer = new Xlsx($spreadsheet);
        $tempFile = tempnam(sys_get_temp_dir(), 'export_');

        assert(is_string($tempFile));

        $writer->save($tempFile);

        $content = file_get_contents($tempFile);
        unlink($tempFile);

        return $content !== false ? $content : '';
    }

    /**
     * @param array<ExportableSolvingTime> $data
     */
    private function toCsv(array $data, OwnCustomColumns $custom): string
    {
        $spreadsheet = $this->createSpreadsheet($data, $custom, forCsv: true);

        $writer = new Csv($spreadsheet);
        $writer->setDelimiter(',');
        $writer->setEnclosure('"');

        $tempFile = tempnam(sys_get_temp_dir(), 'export_');

        assert(is_string($tempFile));

        $writer->save($tempFile);

        $content = file_get_contents($tempFile);
        unlink($tempFile);

        return $content !== false ? $content : '';
    }

    /**
     * @param array<ExportableSolvingTime> $data
     */
    private function toXml(array $data, OwnCustomColumns $custom): string
    {
        $xml = new SimpleXMLElement('<?xml version="1.0" encoding="UTF-8"?><solving_times></solving_times>');

        foreach ($data as $item) {
            $record = $xml->addChild('record');

            foreach ($item->toArray() as $key => $value) {
                $stringValue = match (true) {
                    is_bool($value) => $value ? 'true' : 'false',
                    $value === null => '',
                    is_scalar($value) => (string) $value,
                    default => '',
                };

                $record->addChild($key, htmlspecialchars($stringValue, ENT_XML1, 'UTF-8'));
            }

            if ($custom->isEmpty() === false) {
                $recordElement = dom_import_simplexml($record);
                $document = $recordElement->ownerDocument;
                assert($recordElement instanceof DOMElement && $document !== null);

                foreach ($custom->of($item->timeId) as $label => $value) {
                    OwnCustomColumns::appendXml($document, $recordElement, $label, $value);
                }
            }
        }

        $dom = new DOMDocument('1.0', 'UTF-8');
        $dom->preserveWhiteSpace = false;
        $dom->formatOutput = true;

        $xmlString = $xml->asXML();

        // asXML() returns false on failure and '' is not valid XML either;
        // loadXML() is typed non-empty-string, so both are ruled out here
        assert(is_string($xmlString) && $xmlString !== '');

        $dom->loadXML($xmlString);

        $result = $dom->saveXML();

        return $result !== false ? $result : '';
    }

    /**
     * Cells go through SpreadsheetSafeValue: team member names are typed by other players, and a value starting
     * with "=" must never become a formula in the downloading player's spreadsheet.
     *
     * @param array<ExportableSolvingTime> $data
     */
    private function createSpreadsheet(array $data, OwnCustomColumns $custom, bool $forCsv): Spreadsheet
    {
        $spreadsheet = new Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();

        // Headers
        $col = 1;
        foreach (self::HEADERS as $header) {
            $sheet->setCellValue([$col, 1], $header);
            $col++;
        }

        // A player names their own fields: a header is text, never a formula
        foreach ($custom->labels as $label) {
            $sheet->setCellValueExplicit([$col, 1], $forCsv ? SpreadsheetSafeValue::forCsv($label) : $label, DataType::TYPE_STRING);
            $col++;
        }

        // Data rows
        $row = 2;
        foreach ($data as $item) {
            $values = $item->toArray();
            $col = 1;
            foreach (self::HEADERS as $header) {
                $value = $values[$header] ?? null;
                assert($value === null || is_scalar($value));

                if ($forCsv) {
                    $sheet->setCellValueExplicit([$col, $row], SpreadsheetSafeValue::forCsv($value), DataType::TYPE_STRING);
                } else {
                    SpreadsheetSafeValue::writeXlsxCell($sheet, $col, $row, $value);
                }

                $col++;
            }

            foreach ($custom->of($item->timeId) as $value) {
                $cell = OwnCustomColumns::cell($value);

                if ($forCsv) {
                    $sheet->setCellValueExplicit([$col, $row], SpreadsheetSafeValue::forCsv($cell), DataType::TYPE_STRING);
                } else {
                    SpreadsheetSafeValue::writeXlsxCell($sheet, $col, $row, $cell);
                }

                $col++;
            }
            $row++;
        }

        return $spreadsheet;
    }
}
