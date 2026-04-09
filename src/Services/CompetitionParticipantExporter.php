<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Services;

use Doctrine\DBAL\Connection;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use SpeedPuzzling\Web\Query\GetCompetitionParticipantsForManagement;
use SpeedPuzzling\Web\Query\GetCompetitionRoundsForManagement;

readonly final class CompetitionParticipantExporter
{
    private const array HEADERS = ['name', 'country', 'external_id', 'msp_player_id', 'status', 'round_names', 'team_name'];

    public function __construct(
        private GetCompetitionParticipantsForManagement $getParticipants,
        private GetCompetitionRoundsForManagement $getRounds,
        private Connection $database,
    ) {
    }

    public function export(string $competitionId): string
    {
        $participants = $this->getParticipants->all($competitionId, includeDeleted: false);
        $rounds = $this->getRounds->ofCompetition($competitionId);

        $roundNameMap = [];
        foreach ($rounds as $round) {
            $roundNameMap[$round->id] = $round->name;
        }

        $teamNames = $this->fetchTeamNames($competitionId);

        $spreadsheet = new Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();

        $col = 1;
        foreach (self::HEADERS as $header) {
            $sheet->setCellValue([$col, 1], $header);
            $col++;
        }

        $row = 2;
        foreach ($participants as $participant) {
            $assignedRoundNames = [];
            foreach ($participant->roundIds as $roundId) {
                if (isset($roundNameMap[$roundId])) {
                    $assignedRoundNames[] = $roundNameMap[$roundId];
                }
            }

            $teamName = $teamNames[$participant->participantId] ?? null;

            $sheet->setCellValue([1, $row], $participant->participantName);
            $sheet->setCellValue([2, $row], $participant->participantCountry?->name);
            $sheet->setCellValue([3, $row], $participant->externalId);
            $sheet->setCellValue([4, $row], $participant->playerId);
            $sheet->setCellValue([5, $row], 'active');
            $sheet->setCellValue([6, $row], implode(', ', $assignedRoundNames));
            $sheet->setCellValue([7, $row], $teamName);
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

    /**
     * @return array<string, string> participant_id => team_name
     */
    private function fetchTeamNames(string $competitionId): array
    {
        $query = <<<SQL
SELECT cp.id AS participant_id, ct.name AS team_name
FROM competition_participant cp
INNER JOIN competition_participant_round cpr ON cpr.participant_id = cp.id
INNER JOIN competition_team ct ON ct.id = cpr.team_id
WHERE cp.competition_id = :competitionId
    AND cp.deleted_at IS NULL
    AND ct.name IS NOT NULL
SQL;

        $rows = $this->database
            ->executeQuery($query, ['competitionId' => $competitionId])
            ->fetchAllAssociative();

        $result = [];
        foreach ($rows as $row) {
            /** @var array{participant_id: string, team_name: string} $row */
            $result[$row['participant_id']] = $row['team_name'];
        }

        return $result;
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
