<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Services;

use DOMDocument;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Csv;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use SimpleXMLElement;
use SpeedPuzzling\Web\Results\ExportableSolvingTime;
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
    ];

    /**
     * @param array<ExportableSolvingTime> $data
     */
    public function export(array $data, ExportFormat $format): string
    {
        return match ($format) {
            ExportFormat::Json => $this->toJson($data),
            ExportFormat::Xlsx => $this->toXlsx($data),
            ExportFormat::Csv => $this->toCsv($data),
            ExportFormat::Xml => $this->toXml($data),
        };
    }

    /**
     * @param array<ExportableSolvingTime> $data
     */
    private function toJson(array $data): string
    {
        $rows = array_map(static fn(ExportableSolvingTime $item): array => $item->toArray(), $data);

        return json_encode($rows, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    }

    /**
     * @param array<ExportableSolvingTime> $data
     */
    private function toXlsx(array $data): string
    {
        $spreadsheet = $this->createSpreadsheet($data);

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
    private function toCsv(array $data): string
    {
        $spreadsheet = $this->createSpreadsheet($data);

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
    private function toXml(array $data): string
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
        }

        $dom = new DOMDocument('1.0', 'UTF-8');
        $dom->preserveWhiteSpace = false;
        $dom->formatOutput = true;

        $xmlString = $xml->asXML();

        assert(is_string($xmlString));

        $dom->loadXML($xmlString);

        $result = $dom->saveXML();

        return $result !== false ? $result : '';
    }

    /**
     * @param array<ExportableSolvingTime> $data
     */
    private function createSpreadsheet(array $data): Spreadsheet
    {
        $spreadsheet = new Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();

        // Headers
        $col = 1;
        foreach (self::HEADERS as $header) {
            $sheet->setCellValue([$col, 1], $header);
            $col++;
        }

        // Data rows
        $row = 2;
        foreach ($data as $item) {
            $values = $item->toArray();
            $col = 1;
            foreach (self::HEADERS as $header) {
                $value = $values[$header] ?? '';

                if (is_bool($value)) {
                    $value = $value ? 'true' : 'false';
                }

                $sheet->setCellValue([$col, $row], $value);
                $col++;
            }
            $row++;
        }

        return $spreadsheet;
    }
}
