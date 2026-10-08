<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Services\ParticipantImport;

use DateTimeImmutable;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Services\OfficialResultsGuard;
use SpeedPuzzling\Web\Services\ParticipantImport\Plan\SiteSnapshot;
use SpeedPuzzling\Web\Value\ParticipantImportRound;
use SpeedPuzzling\Web\Value\RoundCategory;

/**
 * The event as it is - participants, round entries, pairs/teams, rounds and who holds results there - read once with
 * DBAL (no entity is touched), for both planners that change it: the participant import (ParticipantImportPlanner)
 * and the participants sheet (SheetChangesPlanner). One reader, so a file and a sheet always see the same event and
 * apply the same results guard (D11, official-results.md "Guards"). A constant number of statements for any event size.
 */
readonly final class SiteSnapshotReader
{
    public function __construct(
        private Connection $database,
        private OfficialResultsGuard $officialResultsGuard,
    ) {
    }

    /**
     * @return list<ParticipantImportRound> ordered by start, name, id
     */
    public function rounds(string $competitionId): array
    {
        $rows = $this->database->fetchAllAssociative(
            'SELECT id, name, category, team_size FROM competition_round WHERE competition_id = :competitionId ORDER BY starts_at, name, id',
            ['competitionId' => $competitionId],
        );

        $rounds = [];
        foreach ($rows as $row) {
            /** @var array{id: string, name: string, category: string, team_size: null|int} $row */
            $rounds[] = new ParticipantImportRound(
                $row['id'],
                $row['name'],
                RoundCategory::from($row['category']),
                $row['team_size'] !== null ? (int) $row['team_size'] : null,
            );
        }

        return $rounds;
    }

    /**
     * @param list<string> $playerIds player ids the caller asks about (a file's msp_player_ids, a sheet's links) - those
     *                                that exist end up in SiteSnapshot::$existingPlayers
     * @param string $stateVersion read by the caller BEFORE calling this: a change committed between the reads then makes
     *                             the plan stale, instead of a plan nobody saw carrying the newer version
     */
    public function read(string $competitionId, array $playerIds, string $stateVersion): SiteSnapshot
    {
        $parameters = ['competitionId' => $competitionId];

        $participants = [];
        $participantRows = $this->database->fetchAllAssociative(
            <<<SQL
SELECT cp.id, cp.name, cp.country, cp.external_id, cp.player_id, cp.deleted_at, cp.source, cp.organizer_note, cp.registration_status,
    p.name AS player_name
FROM competition_participant cp
LEFT JOIN player p ON p.id = cp.player_id
WHERE cp.competition_id = :competitionId
-- Active rows first, so a player's live row wins over a soft-deleted one with the same match
ORDER BY cp.deleted_at IS NOT NULL, cp.id
SQL,
            $parameters,
        );
        foreach ($participantRows as $row) {
            /** @var array{id: string, name: string, country: null|string, external_id: null|string, player_id: null|string, deleted_at: null|string, source: string, organizer_note: null|string, registration_status: null|string, player_name: null|string} $row */
            $participants[] = [
                'id' => $row['id'],
                'name' => $row['name'],
                'country' => $row['country'],
                'externalId' => $row['external_id'],
                'playerId' => $row['player_id'],
                'playerName' => $row['player_name'],
                'deletedAt' => $row['deleted_at'] !== null ? new DateTimeImmutable($row['deleted_at']) : null,
                'selfJoined' => $row['source'] === 'self_joined',
                'organizerNote' => $row['organizer_note'],
                'registrationStatus' => $row['registration_status'],
            ];
        }

        $entries = [];
        $entryRows = $this->database->fetchAllAssociative(
            <<<SQL
SELECT cpr.id, cpr.participant_id, cpr.round_id, cpr.team_id,
    (cpr.result_seconds IS NOT NULL OR cpr.result_pieces_placed IS NOT NULL OR cpr.result_did_not_start OR cpr.qualified_at IS NOT NULL) AS own_official_data
FROM competition_participant_round cpr
INNER JOIN competition_participant cp ON cp.id = cpr.participant_id
WHERE cp.competition_id = :competitionId
ORDER BY cpr.id
SQL,
            $parameters,
        );
        foreach ($entryRows as $row) {
            /** @var array{id: string, participant_id: string, round_id: string, team_id: null|string, own_official_data: bool} $row */
            $entries[] = [
                'id' => $row['id'],
                'participantId' => $row['participant_id'],
                'roundId' => $row['round_id'],
                'teamId' => $row['team_id'],
                'ownOfficialData' => $row['own_official_data'] === true,
            ];
        }

        $teams = [];
        $teamRows = $this->database->fetchAllAssociative(
            <<<SQL
SELECT ct.id, ct.round_id, ct.name
FROM competition_team ct
INNER JOIN competition_round cr ON cr.id = ct.round_id
WHERE cr.competition_id = :competitionId
ORDER BY ct.id
SQL,
            $parameters,
        );
        foreach ($teamRows as $row) {
            /** @var array{id: string, round_id: string, name: null|string} $row */
            $teams[$row['id']] = ['id' => $row['id'], 'roundId' => $row['round_id'], 'name' => $row['name']];
        }

        // A result in a round of this event - the player's own, or as a member of a pair/team (D11)
        $results = [];
        $resultRows = $this->database->fetchAllAssociative(
            <<<SQL
SELECT pst.player_id, pst.competition_round_id AS round_id
FROM puzzle_solving_time pst
INNER JOIN competition_round cr ON cr.id = pst.competition_round_id
WHERE cr.competition_id = :competitionId
UNION
SELECT ptm.player_id, pst.competition_round_id AS round_id
FROM puzzle_solving_time pst
INNER JOIN competition_round cr ON cr.id = pst.competition_round_id
INNER JOIN puzzling_team_member ptm ON ptm.team_id = pst.puzzling_team_id
WHERE cr.competition_id = :competitionId
    AND ptm.player_id IS NOT NULL
SQL,
            $parameters,
        );
        foreach ($resultRows as $row) {
            /** @var array{player_id: string, round_id: string} $row */
            $results[$row['player_id']][$row['round_id']] = true;
        }

        $askedPlayers = [];
        foreach ($playerIds as $playerId) {
            if (Uuid::isValid($playerId)) {
                $askedPlayers[strtolower($playerId)] = true;
            }
        }

        $existingPlayers = [];
        if ($askedPlayers !== []) {
            /** @var list<string> $found */
            $found = $this->database->fetchFirstColumn(
                'SELECT id FROM player WHERE id IN (:ids)',
                ['ids' => array_keys($askedPlayers)],
                ['ids' => ArrayParameterType::STRING],
            );

            foreach ($found as $id) {
                $existingPlayers[strtolower($id)] = true;
            }
        }

        return new SiteSnapshot(
            competitionId: $competitionId,
            rounds: $this->rounds($competitionId),
            participants: $participants,
            entries: $entries,
            teams: $teams,
            results: $results,
            existingPlayers: $existingPlayers,
            stateVersion: $stateVersion,
            officialResults: $this->officialResultsGuard->officialDataByParticipant($competitionId),
            officialTeams: $this->officialResultsGuard->teamsWithOfficialData($competitionId),
        );
    }
}
