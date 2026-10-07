<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Services\ParticipantImport;

use DateTimeImmutable;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Query\GetParticipantImportStateVersion;
use SpeedPuzzling\Web\Results\ParticipantImportPlan;
use SpeedPuzzling\Web\Services\ParticipantImport\Plan\PlanBuilder;
use SpeedPuzzling\Web\Services\ParticipantImport\Plan\SiteSnapshot;
use SpeedPuzzling\Web\Value\ParticipantImportMode;
use SpeedPuzzling\Web\Value\ParticipantImportRound;
use SpeedPuzzling\Web\Value\ParticipantImportRows;
use SpeedPuzzling\Web\Value\RoundCategory;

/**
 * What confirming an import would do - read only (DBAL reads, no entity is touched, no id generated), deterministic:
 * the same file on the same event plans the same, with the same fingerprint. The rules live in PlanBuilder.
 *
 * docs/features/competitions-management/participant-import-preview.md
 */
readonly final class ParticipantImportPlanner
{
    public function __construct(
        private Connection $database,
        private GetParticipantImportStateVersion $getStateVersion,
    ) {
    }

    /**
     * @return list<ParticipantImportRound> ordered by start, name, id
     */
    public function rounds(string $competitionId): array
    {
        $rows = $this->database->fetchAllAssociative(
            'SELECT id, name, category FROM competition_round WHERE competition_id = :competitionId ORDER BY starts_at, name, id',
            ['competitionId' => $competitionId],
        );

        $rounds = [];
        foreach ($rows as $row) {
            /** @var array{id: string, name: string, category: string} $row */
            $rounds[] = new ParticipantImportRound($row['id'], $row['name'], RoundCategory::from($row['category']));
        }

        return $rounds;
    }

    public function plan(string $competitionId, ParticipantImportRows $rows, ParticipantImportMode $mode): ParticipantImportPlan
    {
        $site = $this->snapshot($competitionId, $rows);

        $built = (new PlanBuilder($site, $rows, $mode))->build();

        // "Update only" shows what full sync would remove too (D7) - the same rows planned the other way
        $removals = $mode === ParticipantImportMode::Sync
            ? $built->removals
            : (new PlanBuilder($site, $rows, ParticipantImportMode::Sync))->build()->removals;

        $existingPlayers = array_keys($site->existingPlayers);
        sort($existingPlayers);

        $fingerprint = hash('sha256', json_encode([
            $rows->hash(),
            $mode->value,
            $site->stateVersion,
            // Results are not in the state version; what the plan keeps because of them is (D8, D11)
            $built->resultsGuard,
            // The file's msp_player_ids that exist - a player deleted meanwhile is not connected
            $existingPlayers,
        ], JSON_THROW_ON_ERROR));

        return new ParticipantImportPlan(
            mode: $mode,
            rows: $built->rows,
            warnings: $built->warnings,
            errors: [],
            removals: $removals,
            operations: $built->operations,
            fingerprint: $fingerprint,
            syncBlockers: $built->syncBlockers,
            activeParticipantsBefore: $built->activeParticipantsBefore,
        );
    }

    private function snapshot(string $competitionId, ParticipantImportRows $rows): SiteSnapshot
    {
        $parameters = ['competitionId' => $competitionId];

        // First, before the data: a change committed between the reads below then makes the plan stale on confirm,
        // instead of a plan nobody saw carrying the newer version
        $stateVersion = $this->getStateVersion->ofCompetition($competitionId);

        $participants = [];
        $participantRows = $this->database->fetchAllAssociative(
            <<<SQL
SELECT cp.id, cp.name, cp.country, cp.external_id, cp.player_id, cp.deleted_at, cp.source, p.name AS player_name
FROM competition_participant cp
LEFT JOIN player p ON p.id = cp.player_id
WHERE cp.competition_id = :competitionId
-- Active rows first, so a player's live row wins over a soft-deleted one with the same match
ORDER BY cp.deleted_at IS NOT NULL, cp.id
SQL,
            $parameters,
        );
        foreach ($participantRows as $row) {
            /** @var array{id: string, name: string, country: null|string, external_id: null|string, player_id: null|string, deleted_at: null|string, source: string, player_name: null|string} $row */
            $participants[] = [
                'id' => $row['id'],
                'name' => $row['name'],
                'country' => $row['country'],
                'externalId' => $row['external_id'],
                'playerId' => $row['player_id'],
                'playerName' => $row['player_name'],
                'deletedAt' => $row['deleted_at'] !== null ? new DateTimeImmutable($row['deleted_at']) : null,
                'selfJoined' => $row['source'] === 'self_joined',
            ];
        }

        $entries = [];
        $entryRows = $this->database->fetchAllAssociative(
            <<<SQL
SELECT cpr.id, cpr.participant_id, cpr.round_id, cpr.team_id
FROM competition_participant_round cpr
INNER JOIN competition_participant cp ON cp.id = cpr.participant_id
WHERE cp.competition_id = :competitionId
ORDER BY cpr.id
SQL,
            $parameters,
        );
        foreach ($entryRows as $row) {
            /** @var array{id: string, participant_id: string, round_id: string, team_id: null|string} $row */
            $entries[] = [
                'id' => $row['id'],
                'participantId' => $row['participant_id'],
                'roundId' => $row['round_id'],
                'teamId' => $row['team_id'],
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

        $playerIds = [];
        foreach ($rows->rows as $row) {
            if ($row->playerId !== null && Uuid::isValid($row->playerId)) {
                $playerIds[strtolower($row->playerId)] = true;
            }
        }

        $existingPlayers = [];
        if ($playerIds !== []) {
            /** @var list<string> $found */
            $found = $this->database->fetchFirstColumn(
                'SELECT id FROM player WHERE id IN (:ids)',
                ['ids' => array_keys($playerIds)],
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
        );
    }
}
