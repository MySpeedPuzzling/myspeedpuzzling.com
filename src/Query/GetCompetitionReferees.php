<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Query;

use Doctrine\DBAL\Connection;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Results\CompetitionRefereeListItem;

/**
 * The referees of one competition for its organisers' referees page (live-results.md "Referees"), by name.
 *
 * ofCompetition() - who they are (also the event wizard's Extras step); withActivity() - the same rows with each
 * referee's activity for the referees page: how many results whose last change was theirs, and that last change
 * (judge-speed J1). The activity comes from `result_entered_by_id` / `result_entered_at` of the entries and teams - a
 * result changed later by somebody else counts for them, a cleared result counts nothing but moves "last".
 */
readonly final class GetCompetitionReferees
{
    public function __construct(
        private Connection $database,
    ) {
    }

    /**
     * @return list<CompetitionRefereeListItem>
     */
    public function ofCompetition(string $competitionId): array
    {
        if (Uuid::isValid($competitionId) === false) {
            return [];
        }

        $query = <<<SQL
SELECT
    player.id AS player_id,
    player.code AS player_code,
    player.name AS player_name,
    player.country AS player_country,
    referee.added_at,
    COALESCE(added_by.name, '#' || UPPER(added_by.code)) AS added_by_name
FROM competition_referee referee
INNER JOIN player ON player.id = referee.player_id
LEFT JOIN player added_by ON added_by.id = referee.added_by_id
WHERE referee.competition_id = :competitionId
ORDER BY LOWER(COALESCE(player.name, player.code)), player.code
SQL;

        $rows = $this->database->fetchAllAssociative($query, ['competitionId' => $competitionId]);

        return array_map(static function (array $row): CompetitionRefereeListItem {
            /** @var array{player_id: string, player_code: string, player_name: null|string, player_country: null|string, added_at: string, added_by_name: null|string} $row */
            return CompetitionRefereeListItem::fromDatabaseRow($row);
        }, $rows);
    }

    /**
     * The same rows as ofCompetition() in the same order, with each referee's activity in this competition - one
     * statement (the aggregate is a CTE over the competition's rounds, index path competition_round(competition_id) →
     * entries/teams by round_id).
     *
     * @return list<CompetitionRefereeListItem>
     */
    public function withActivity(string $competitionId): array
    {
        if (Uuid::isValid($competitionId) === false) {
            return [];
        }

        $query = <<<SQL
WITH entered AS (
    SELECT
        cpr.result_entered_by_id AS player_id,
        cpr.result_entered_at AS entered_at,
        cpr.round_id,
        (cpr.result_seconds IS NOT NULL OR cpr.result_pieces_placed IS NOT NULL OR cpr.result_did_not_start) AS has_result
    FROM competition_participant_round cpr
    INNER JOIN competition_round r ON r.id = cpr.round_id AND r.competition_id = :competitionId
    WHERE cpr.result_entered_by_id IS NOT NULL
    UNION ALL
    SELECT
        ct.result_entered_by_id,
        ct.result_entered_at,
        ct.round_id,
        (ct.result_seconds IS NOT NULL OR ct.result_pieces_placed IS NOT NULL OR ct.result_did_not_start)
    FROM competition_team ct
    INNER JOIN competition_round r ON r.id = ct.round_id AND r.competition_id = :competitionId
    WHERE ct.result_entered_by_id IS NOT NULL
), activity AS (
    SELECT
        player_id,
        COUNT(*) FILTER (WHERE has_result) AS results,
        MAX(entered_at) AS last_at,
        (ARRAY_AGG(round_id ORDER BY entered_at DESC NULLS LAST, round_id))[1] AS last_round_id
    FROM entered
    GROUP BY player_id
)
SELECT
    player.id AS player_id,
    player.code AS player_code,
    player.name AS player_name,
    player.country AS player_country,
    referee.added_at,
    COALESCE(added_by.name, '#' || UPPER(added_by.code)) AS added_by_name,
    COALESCE(activity.results, 0) AS results_entered,
    activity.last_at AS last_entered_at,
    last_round.name AS last_round_name,
    last_round.timezone AS last_round_timezone,
    last_competition.location_country_code AS last_round_country_code,
    last_series.location_country_code AS last_round_series_country_code
FROM competition_referee referee
INNER JOIN player ON player.id = referee.player_id
LEFT JOIN player added_by ON added_by.id = referee.added_by_id
LEFT JOIN activity ON activity.player_id = referee.player_id
LEFT JOIN competition_round last_round ON last_round.id = activity.last_round_id
LEFT JOIN competition last_competition ON last_competition.id = last_round.competition_id
LEFT JOIN competition_series last_series ON last_series.id = last_competition.series_id
WHERE referee.competition_id = :competitionId
ORDER BY LOWER(COALESCE(player.name, player.code)), player.code
SQL;

        $rows = $this->database->fetchAllAssociative($query, ['competitionId' => $competitionId]);

        return array_map(static function (array $row): CompetitionRefereeListItem {
            /** @var array{player_id: string, player_code: string, player_name: null|string, player_country: null|string, added_at: string, added_by_name: null|string, results_entered: int|string, last_entered_at: null|string, last_round_name: null|string, last_round_timezone: null|string, last_round_country_code: null|string, last_round_series_country_code: null|string} $row */
            return CompetitionRefereeListItem::fromDatabaseRowWithActivity($row);
        }, $rows);
    }
}
