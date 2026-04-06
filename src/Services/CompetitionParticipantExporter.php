<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Services;

use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use SpeedPuzzling\Web\Query\GetCompetitionParticipantsForManagement;

readonly final class CompetitionParticipantExporter
{
    private const array HEADERS = ['name', 'country', 'external_id', 'msp_player_id', 'status'];

    public function __construct(
        private GetCompetitionParticipantsForManagement $getParticipants,
    ) {
    }

    public function export(string $competitionId): string
    {
        $participants = $this->getParticipants->all($competitionId, includeDeleted: false);

        $spreadsheet = new Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();

        $col = 1;
        foreach (self::HEADERS as $header) {
            $sheet->setCellValue([$col, 1], $header);
            $col++;
        }

        $row = 2;
        foreach ($participants as $participant) {
            $sheet->setCellValue([1, $row], $participant->participantName);
            $sheet->setCellValue([2, $row], $participant->participantCountry?->name);
            $sheet->setCellValue([3, $row], $participant->externalId);
            $sheet->setCellValue([4, $row], $participant->playerId);
            $sheet->setCellValue([5, $row], 'active');
            $row++;
        }

        return $this->writeToString($spreadsheet);
    }

    public function downloadTemplate(): string
    {
        $spreadsheet = new Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();

        $col = 1;
        foreach (self::HEADERS as $header) {
            $sheet->setCellValue([$col, 1], $header);
            $col++;
        }

        return $this->writeToString($spreadsheet);
    }

    private function writeToString(Spreadsheet $spreadsheet): string
    {
        $writer = new Xlsx($spreadsheet);
        $tempFile = tempnam(sys_get_temp_dir(), 'export_');

        assert(is_string($tempFile));

        $writer->save($tempFile);

        $content = file_get_contents($tempFile);
        unlink($tempFile);

        return $content !== false ? $content : '';
    }
}
