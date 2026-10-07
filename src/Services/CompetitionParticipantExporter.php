<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Services;

use Doctrine\DBAL\Connection;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use SpeedPuzzling\Web\Query\GetCompetitionParticipantsForManagement;
use SpeedPuzzling\Web\Query\GetCompetitionRoundsForManagement;
use SpeedPuzzling\Web\Repository\CompetitionRepository;
use SpeedPuzzling\Web\Value\RegistrationStatus;
use SpeedPuzzling\Web\Value\RoundCategory;
use SpeedPuzzling\Web\Value\RoundTimezone;

/**
 * The export is meant to be edited and imported back: imported unchanged it changes nothing.
 * So it carries the participant's id and one team column per pair/team round - a team belongs
 * to one round, a single team_name would land in every pair/team round of the row.
 */
readonly final class CompetitionParticipantExporter
{
    /** The columns of the downloadable template - an event-less sheet for new participants. */
    private const array TEMPLATE_HEADERS = ['name', 'country', 'external_id', 'msp_player_id', 'status', 'round_names', 'team_name'];

    /**
     * Only in the export of an event that manages registration, after every other column
     * (docs/features/competitions-management/registration.md): what the organiser sees on the participants page.
     * The import knows them and reads nothing from them - registrations change on the site, never through a file.
     */
    public const array REGISTRATION_HEADERS = ['registration_status', 'paid_at', 'checked_in_at'];

    public function __construct(
        private GetCompetitionParticipantsForManagement $getParticipants,
        private GetCompetitionRoundsForManagement $getRounds,
        private Connection $database,
        private CompetitionRepository $competitionRepository,
    ) {
    }

    public function export(string $competitionId): string
    {
        $participants = $this->getParticipants->all($competitionId, includeDeleted: false);
        $rounds = $this->getRounds->ofCompetition($competitionId);

        $roundNameMap = [];
        /** @var array<string, string> $teamRounds roundId => round name, pair/team rounds only */
        $teamRounds = [];
        foreach ($rounds as $round) {
            $roundNameMap[$round->id] = $round->name;

            if ($round->category !== RoundCategory::Solo) {
                $teamRounds[$round->id] = $round->name;
            }
        }

        $teamNames = $this->fetchTeamNames($competitionId);

        $headers = self::TEMPLATE_HEADERS;
        foreach ($teamRounds as $roundName) {
            $headers[] = CompetitionParticipantImporter::TEAM_COLUMN_PREFIX . ' ' . $roundName;
        }
        $headers[] = 'participant_id';

        $competition = $this->competitionRepository->get($competitionId);
        $registrationTimezone = $competition->registrationManaged
            ? RoundTimezone::resolve($competition->registrationTimezone, $competition->locationCountryCode, $competition->series?->locationCountryCode)
            : null;

        if ($registrationTimezone !== null) {
            $headers = [...$headers, ...self::REGISTRATION_HEADERS];
        }

        $spreadsheet = new Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();

        $col = 1;
        foreach ($headers as $header) {
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

            $values = [
                $participant->participantName,
                $participant->participantCountry?->name,
                $participant->externalId,
                $participant->playerId,
                'active',
                implode(', ', $assignedRoundNames),
                null, // team_name: one team for every pair/team round of the row - never written, the per-round columns are
            ];

            foreach (array_keys($teamRounds) as $roundId) {
                $values[] = $teamNames[$participant->participantId][$roundId] ?? null;
            }

            $values[] = $participant->participantId;

            if ($registrationTimezone !== null) {
                // Rows without a status hold a spot - reserved, as on the participants page
                $values[] = ($participant->registrationStatus ?? RegistrationStatus::Reserved)->value;
                $values[] = $participant->paidAt !== null ? RoundTimezone::toLocal($participant->paidAt, $registrationTimezone)->format('Y-m-d H:i') : null;
                $values[] = $participant->checkedInAt !== null ? RoundTimezone::toLocal($participant->checkedInAt, $registrationTimezone)->format('Y-m-d H:i') : null;
            }

            $col = 1;
            foreach ($values as $value) {
                $sheet->setCellValueExplicit([$col, $row], $value, DataType::TYPE_STRING);
                $col++;
            }

            $row++;
        }

        return $this->writeToString($spreadsheet);
    }

    public function downloadTemplate(): string
    {
        $spreadsheet = new Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();

        $col = 1;
        foreach (self::TEMPLATE_HEADERS as $header) {
            $sheet->setCellValue([$col, 1], $header);
            $col++;
        }

        return $this->writeToString($spreadsheet);
    }

    /**
     * @return array<string, array<string, string>> participant_id => [round_id => team name]
     */
    private function fetchTeamNames(string $competitionId): array
    {
        $query = <<<SQL
SELECT cp.id AS participant_id, cpr.round_id, ct.name AS team_name
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
            /** @var array{participant_id: string, round_id: string, team_name: string} $row */
            $result[$row['participant_id']][$row['round_id']] = $row['team_name'];
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
