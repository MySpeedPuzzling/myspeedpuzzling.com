<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Query;

use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use SpeedPuzzling\Web\Results\RoundResultMember;
use SpeedPuzzling\Web\Results\RoundResultRow;
use SpeedPuzzling\Web\Value\CountryCode;

readonly final class GetRoundResults
{
    public function __construct(
        private Connection $database,
    ) {
    }

    /**
     * Standings for a round. Rank is computed: finished results by time ascending,
     * then DNFs by missing pieces ascending (unknown last). Equal values share a rank.
     *
     * @return array<RoundResultRow>
     */
    public function standings(string $roundId): array
    {
        $query = <<<SQL
SELECT
    rr.id AS result_id,
    rr.participant_id,
    rr.team_id,
    rr.seconds_to_solve,
    rr.missing_pieces,
    COALESCE(cp.name, ct.name) AS entrant_name,
    RANK() OVER (
        ORDER BY
            (rr.seconds_to_solve IS NULL),
            rr.seconds_to_solve ASC,
            (rr.missing_pieces IS NULL),
            rr.missing_pieces ASC
    ) AS rank
FROM round_result rr
LEFT JOIN competition_participant cp ON cp.id = rr.participant_id
LEFT JOIN competition_team ct ON ct.id = rr.team_id
WHERE rr.round_id = :roundId
ORDER BY rank ASC, entrant_name ASC NULLS LAST
SQL;

        /** @var array<array{result_id: string, participant_id: null|string, team_id: null|string, seconds_to_solve: null|int|string, missing_pieces: null|int|string, entrant_name: null|string, rank: int|string}> $rows */
        $rows = $this->database->executeQuery($query, ['roundId' => $roundId])->fetchAllAssociative();

        $soloParticipantIds = [];
        $teamIds = [];

        foreach ($rows as $row) {
            if ($row['participant_id'] !== null) {
                $soloParticipantIds[] = $row['participant_id'];
            }
            if ($row['team_id'] !== null) {
                $teamIds[] = $row['team_id'];
            }
        }

        $membersByParticipant = $this->loadMembersByParticipantIds($soloParticipantIds);
        $membersByTeam = $this->loadMembersByTeamIds($teamIds);

        return array_map(static function (array $row) use ($membersByParticipant, $membersByTeam): RoundResultRow {
            $members = [];

            if ($row['participant_id'] !== null && isset($membersByParticipant[$row['participant_id']])) {
                $members = [$membersByParticipant[$row['participant_id']]];
            } elseif ($row['team_id'] !== null) {
                $members = $membersByTeam[$row['team_id']] ?? [];
            }

            return new RoundResultRow(
                resultId: $row['result_id'],
                rank: (int) $row['rank'],
                entrantName: $row['entrant_name'],
                secondsToSolve: $row['seconds_to_solve'] !== null ? (int) $row['seconds_to_solve'] : null,
                missingPieces: $row['missing_pieces'] !== null ? (int) $row['missing_pieces'] : null,
                participantId: $row['participant_id'],
                teamId: $row['team_id'],
                members: $members,
            );
        }, $rows);
    }

    /**
     * Round entrants (participants for solo rounds, teams otherwise) that have no result yet.
     *
     * @return array<array{id: string, name: null|string, type: string}>
     */
    public function entrantsWithoutResult(string $roundId): array
    {
        $query = <<<SQL
SELECT cp.id, cp.name, 'participant' AS type
FROM competition_participant_round cpr
INNER JOIN competition_participant cp ON cp.id = cpr.participant_id AND cp.deleted_at IS NULL
INNER JOIN competition_round r ON r.id = cpr.round_id
WHERE cpr.round_id = :roundId
AND r.category = 'solo'
AND NOT EXISTS (SELECT 1 FROM round_result rr WHERE rr.round_id = :roundId AND rr.participant_id = cp.id)

UNION ALL

SELECT ct.id, ct.name, 'team' AS type
FROM competition_team ct
INNER JOIN competition_round r ON r.id = ct.round_id
WHERE ct.round_id = :roundId
AND r.category != 'solo'
AND NOT EXISTS (SELECT 1 FROM round_result rr WHERE rr.round_id = :roundId AND rr.team_id = ct.id)

ORDER BY name ASC NULLS LAST
SQL;

        /** @var array<array{id: string, name: null|string, type: string}> $rows */
        $rows = $this->database->executeQuery($query, ['roundId' => $roundId])->fetchAllAssociative();

        return $rows;
    }

    /**
     * Published rounds of a competition with their standings, ordered by round start.
     *
     * @return array<array{roundId: string, roundName: string, category: string, badgeBackgroundColor: null|string, badgeTextColor: null|string, results: array<RoundResultRow>}>
     */
    public function publishedStandingsForCompetition(string $competitionId): array
    {
        $query = <<<SQL
SELECT r.id, r.name, r.category, r.badge_background_color, r.badge_text_color
FROM competition_round r
WHERE r.competition_id = :competitionId
AND r.results_published_at IS NOT NULL
AND EXISTS (SELECT 1 FROM round_result rr WHERE rr.round_id = r.id)
ORDER BY r.starts_at ASC
SQL;

        /** @var array<array{id: string, name: string, category: string, badge_background_color: null|string, badge_text_color: null|string}> $rounds */
        $rounds = $this->database->executeQuery($query, ['competitionId' => $competitionId])->fetchAllAssociative();

        $standings = [];

        foreach ($rounds as $round) {
            $standings[] = [
                'roundId' => $round['id'],
                'roundName' => $round['name'],
                'category' => $round['category'],
                'badgeBackgroundColor' => $round['badge_background_color'],
                'badgeTextColor' => $round['badge_text_color'],
                'results' => $this->standings($round['id']),
            ];
        }

        return $standings;
    }

    public function hasPublishedResults(string $competitionId): bool
    {
        $query = <<<SQL
SELECT 1
FROM competition_round r
WHERE r.competition_id = :competitionId
AND r.results_published_at IS NOT NULL
AND EXISTS (SELECT 1 FROM round_result rr WHERE rr.round_id = r.id)
LIMIT 1
SQL;

        return $this->database->executeQuery($query, ['competitionId' => $competitionId])->fetchOne() !== false;
    }

    /**
     * @param array<string> $participantIds
     * @return array<string, RoundResultMember>
     */
    private function loadMembersByParticipantIds(array $participantIds): array
    {
        if ($participantIds === []) {
            return [];
        }

        $query = <<<SQL
SELECT cp.id AS participant_id, cp.name, cp.country,
    p.id AS player_id, p.name AS player_name, p.code AS player_code, p.is_private
FROM competition_participant cp
LEFT JOIN player p ON p.id = cp.player_id
WHERE cp.id IN (:ids)
SQL;

        /** @var array<array{participant_id: string, name: string, country: null|string, player_id: null|string, player_name: null|string, player_code: null|string, is_private: null|bool|string}> $rows */
        $rows = $this->database->executeQuery(
            $query,
            ['ids' => $participantIds],
            ['ids' => ArrayParameterType::STRING],
        )->fetchAllAssociative();

        $members = [];
        foreach ($rows as $row) {
            $members[$row['participant_id']] = $this->hydrateMember($row);
        }

        return $members;
    }

    /**
     * @param array<string> $teamIds
     * @return array<string, array<RoundResultMember>>
     */
    private function loadMembersByTeamIds(array $teamIds): array
    {
        if ($teamIds === []) {
            return [];
        }

        $query = <<<SQL
SELECT cpr.team_id, cp.id AS participant_id, cp.name, cp.country,
    p.id AS player_id, p.name AS player_name, p.code AS player_code, p.is_private
FROM competition_participant_round cpr
INNER JOIN competition_participant cp ON cp.id = cpr.participant_id AND cp.deleted_at IS NULL
LEFT JOIN player p ON p.id = cp.player_id
WHERE cpr.team_id IN (:ids)
ORDER BY cp.name
SQL;

        /** @var array<array{team_id: string, participant_id: string, name: string, country: null|string, player_id: null|string, player_name: null|string, player_code: null|string, is_private: null|bool|string}> $rows */
        $rows = $this->database->executeQuery(
            $query,
            ['ids' => $teamIds],
            ['ids' => ArrayParameterType::STRING],
        )->fetchAllAssociative();

        $members = [];
        foreach ($rows as $row) {
            $members[$row['team_id']][] = $this->hydrateMember($row);
        }

        return $members;
    }

    /**
     * @param array{participant_id: string, name: string, country: null|string, player_id: null|string, player_name: null|string, player_code: null|string, is_private: null|bool|string} $row
     */
    private function hydrateMember(array $row): RoundResultMember
    {
        $isPrivate = $row['is_private'];
        if (is_string($isPrivate)) {
            $isPrivate = $isPrivate === 't' || $isPrivate === '1' || $isPrivate === 'true';
        }

        return new RoundResultMember(
            participantId: $row['participant_id'],
            name: $row['name'],
            country: CountryCode::fromCode($row['country']),
            playerId: $row['player_id'],
            playerName: $row['player_name'],
            playerCode: $row['player_code'],
            isPrivate: $isPrivate ?? false,
        );
    }
}
