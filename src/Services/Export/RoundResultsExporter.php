<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Services\Export;

use DateTimeZone;
use LogicException;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use SpeedPuzzling\Web\Results\Export\ExportFile;
use SpeedPuzzling\Web\Results\RoundResultEntry;
use SpeedPuzzling\Web\Results\RoundResultEntryMember;
use SpeedPuzzling\Web\Value\ExportFormat;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * One round's official results as a spreadsheet for the organiser (results desk "Export"): one row per entry in
 * ranking order - also did not start and no result yet - with rank, table, entrant, members, country, the result
 * as the pages show it plus its raw number, qualified, and who entered the result when.
 *
 * One table, so CSV is a single file (not the data export's ZIP of sections) and XLSX a single sheet. Every cell
 * goes through SpreadsheetSafeValue: entrants' names are typed by people, and a spreadsheet must never run them as
 * formulas. Headers and values are in the organiser's language; times are h:mm:ss, dates in the round's zone.
 */
readonly final class RoundResultsExporter
{
    public function __construct(
        private TranslatorInterface $translator,
    ) {
    }

    /**
     * @param list<RoundResultEntry> $entries in ranking order (GetRoundResultEntries::forRound())
     */
    public function export(array $entries, null|int $piecesCount, string $timezone, ExportFormat $format): ExportFile
    {
        $rows = [$this->header()];

        foreach ($entries as $entry) {
            $rows[] = $this->row($entry, $piecesCount, new DateTimeZone($timezone));
        }

        return match ($format) {
            ExportFormat::Csv => new ExportFile($this->csv($rows), 'text/csv; charset=UTF-8', 'csv'),
            ExportFormat::Xlsx => new ExportFile($this->xlsx($rows), $format->contentType(), 'xlsx'),
            default => throw new LogicException('Round results export only as CSV or XLSX.'),
        };
    }

    /**
     * @return list<string>
     */
    private function header(): array
    {
        return array_map(
            fn (string $column): string => $this->translator->trans('results_desk.export.column.' . $column),
            ['rank', 'table', 'entrant', 'members', 'country', 'result', 'seconds', 'pieces_placed', 'qualified', 'entered_by', 'entered_at'],
        );
    }

    /**
     * @return list<null|int|string>
     */
    private function row(RoundResultEntry $entry, null|int $piecesCount, DateTimeZone $zone): array
    {
        return [
            $entry->rank,
            $entry->tableNumber,
            $entry->displayName(),
            implode(', ', array_map(static fn (RoundResultEntryMember $member): string => $member->name, $entry->members)),
            implode(', ', array_map(strtoupper(...), $entry->countries)),
            $this->resultText($entry, $piecesCount),
            $entry->result->seconds,
            $entry->result->piecesPlaced,
            $entry->isQualified() ? $this->translator->trans('results_desk.export.yes') : null,
            $entry->resultEnteredByName,
            $entry->resultEnteredAt?->setTimezone($zone)->format('Y-m-d H:i:s'),
        ];
    }

    private function resultText(RoundResultEntry $entry, null|int $piecesCount): null|string
    {
        $result = $entry->result;

        if ($result->seconds !== null) {
            return sprintf('%d:%02d:%02d', intdiv($result->seconds, 3600), intdiv($result->seconds % 3600, 60), $result->seconds % 60);
        }

        if ($result->piecesPlaced !== null) {
            return $piecesCount !== null
                ? $this->translator->trans('official_results.result.pieces_placed_of', ['%placed%' => $result->piecesPlaced, '%pieces%' => $piecesCount])
                : $this->translator->trans('official_results.result.pieces_placed', ['%placed%' => $result->piecesPlaced]);
        }

        if ($result->didNotStart) {
            return $this->translator->trans('official_results.result.did_not_start');
        }

        return null;
    }

    /**
     * @param list<list<null|int|string>> $rows
     */
    private function csv(array $rows): string
    {
        $handle = fopen('php://temp', 'r+');
        assert($handle !== false);

        // A BOM, so a spreadsheet opened by double click reads names with accents as UTF-8
        fwrite($handle, "\xEF\xBB\xBF");

        foreach ($rows as $row) {
            fputcsv($handle, array_map(SpreadsheetSafeValue::forCsv(...), $row), ',', '"', '');
        }

        rewind($handle);
        $content = stream_get_contents($handle);
        fclose($handle);

        return $content !== false ? $content : '';
    }

    /**
     * The sheet's name in the organiser's language - without the characters a sheet name may not have, at most 31 long.
     */
    public static function sheetTitle(string $title): string
    {
        $title = trim((string) preg_replace('~[:\\\\/?*\[\]]~u', ' ', $title));
        $title = mb_substr($title, 0, 31);

        return $title !== '' ? $title : 'Results';
    }

    /**
     * @param list<list<null|int|string>> $rows
     */
    private function xlsx(array $rows): string
    {
        $spreadsheet = new Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle(self::sheetTitle($this->translator->trans('results_desk.export.sheet')));

        foreach ($rows as $rowIndex => $values) {
            foreach ($values as $columnIndex => $value) {
                if ($rowIndex === 0) {
                    $sheet->setCellValueExplicit([$columnIndex + 1, 1], (string) $value, DataType::TYPE_STRING);

                    continue;
                }

                SpreadsheetSafeValue::writeXlsxCell($sheet, $columnIndex + 1, $rowIndex + 1, $value);
            }
        }

        $columns = count($rows[0] ?? []);
        $sheet->getStyle([1, 1, max(1, $columns), 1])->getFont()->setBold(true);
        $sheet->freezePane('A2');

        for ($column = 1; $column <= $columns; $column++) {
            $sheet->getColumnDimensionByColumn($column)->setAutoSize(true);
        }

        $tempFile = tempnam(sys_get_temp_dir(), 'round_results_');
        assert(is_string($tempFile));

        try {
            new Xlsx($spreadsheet)->save($tempFile);
            $content = file_get_contents($tempFile);
        } finally {
            unlink($tempFile);
        }

        return $content !== false ? $content : '';
    }
}
