<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Query;

use Doctrine\DBAL\Connection;
use SpeedPuzzling\Web\Results\RoundTeamDetail;
use SpeedPuzzling\Web\Results\RoundTeamMember;

readonly final class GetRoundTeams
{
    public function __construct(
        private Connection $database,
    ) {
    }

    /**
     * Teams across all duo/team rounds of a competition, for the join/claim picker.
     *
     * @return array<array{teamId: string, teamName: null|string, roundId: string, roundName: string, memberNames: null|string}>
     */
    public function teamsForCompetition(string $competitionId): array
    {
        $query = <<<SQL
SELECT ct.id AS team_id, ct.name AS team_name, r.id AS round_id, r.name AS round_name,
    (
        SELECT STRING_AGG(cp.name, ', ' ORDER BY cp.name)
        FROM competition_participant_round cpr
        INNER JOIN competition_participant cp ON cp.id = cpr.participant_id AND cp.deleted_at IS NULL
        WHERE cpr.team_id = ct.id
    ) AS member_names
FROM competition_team ct
INNER JOIN competition_round r ON r.id = ct.round_id
WHERE r.competition_id = :competitionId
ORDER BY r.starts_at ASC, ct.name ASC NULLS LAST
SQL;

        /** @var array<array{team_id: string, team_name: null|string, round_id: string, round_name: string, member_names: null|string}> $rows */
        $rows = $this->database->executeQuery($query, ['competitionId' => $competitionId])->fetchAllAssociative();

        return array_map(static fn (array $row): array => [
            'teamId' => $row['team_id'],
            'teamName' => $row['team_name'],
            'roundId' => $row['round_id'],
            'roundName' => $row['round_name'],
            'memberNames' => $row['member_names'],
        ], $rows);
    }

    /**
     * @return array<RoundTeamDetail>
     */
    public function forRound(string $roundId): array
    {
        $teamsQuery = <<<SQL
SELECT ct.id, ct.name
FROM competition_team ct
WHERE ct.round_id = :roundId
ORDER BY ct.name NULLS LAST
SQL;

        $teams = $this->database
            ->executeQuery($teamsQuery, ['roundId' => $roundId])
            ->fetchAllAssociative();

        if ($teams === []) {
            return [];
        }

        $teamIds = array_column($teams, 'id');

        $membersQuery = <<<SQL
SELECT
    cpr.id AS participant_round_id,
    cpr.team_id,
    cp.name AS participant_name,
    cp.country AS participant_country,
    p.id AS player_id,
    p.name AS player_name
FROM competition_participant_round cpr
INNER JOIN competition_participant cp ON cp.id = cpr.participant_id
LEFT JOIN player p ON p.id = cp.player_id
WHERE cpr.team_id IN (:teamIds)
    AND cp.deleted_at IS NULL
ORDER BY cp.name
SQL;

        $memberRows = $this->database
            ->executeQuery(
                $membersQuery,
                ['teamIds' => $teamIds],
                ['teamIds' => \Doctrine\DBAL\ArrayParameterType::STRING],
            )
            ->fetchAllAssociative();

        /** @var array<string, array<RoundTeamMember>> $membersByTeam */
        $membersByTeam = [];
        foreach ($memberRows as $row) {
            /** @var array{participant_round_id: string, team_id: string, participant_name: string, participant_country: null|string, player_id: null|string, player_name: null|string} $row */
            $membersByTeam[$row['team_id']][] = new RoundTeamMember(
                participantRoundId: $row['participant_round_id'],
                participantName: $row['participant_name'],
                participantCountry: $row['participant_country'],
                playerId: $row['player_id'],
                playerName: $row['player_name'],
            );
        }

        return array_map(static function (array $row) use ($membersByTeam): RoundTeamDetail {
            /** @var array{id: string, name: null|string} $row */
            return new RoundTeamDetail(
                id: $row['id'],
                name: $row['name'],
                members: $membersByTeam[$row['id']] ?? [],
            );
        }, $teams);
    }

    /**
     * @return array<RoundTeamMember>
     */
    public function unassignedParticipants(string $roundId): array
    {
        $query = <<<SQL
SELECT
    cpr.id AS participant_round_id,
    cp.name AS participant_name,
    cp.country AS participant_country,
    p.id AS player_id,
    p.name AS player_name
FROM competition_participant_round cpr
INNER JOIN competition_participant cp ON cp.id = cpr.participant_id
LEFT JOIN player p ON p.id = cp.player_id
WHERE cpr.round_id = :roundId
    AND cpr.team_id IS NULL
    AND cp.deleted_at IS NULL
ORDER BY cp.name
SQL;

        $rows = $this->database
            ->executeQuery($query, ['roundId' => $roundId])
            ->fetchAllAssociative();

        return array_map(static function (array $row): RoundTeamMember {
            /** @var array{participant_round_id: string, participant_name: string, participant_country: null|string, player_id: null|string, player_name: null|string} $row */
            return new RoundTeamMember(
                participantRoundId: $row['participant_round_id'],
                participantName: $row['participant_name'],
                participantCountry: $row['participant_country'],
                playerId: $row['player_id'],
                playerName: $row['player_name'],
            );
        }, $rows);
    }
}
