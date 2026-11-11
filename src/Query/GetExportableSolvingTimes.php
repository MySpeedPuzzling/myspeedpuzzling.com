<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Query;

use Doctrine\DBAL\Connection;
use Psr\Clock\ClockInterface;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Exceptions\PlayerNotFound;
use SpeedPuzzling\Web\Results\ExportableSolvingTime;
use SpeedPuzzling\Web\Services\HiddenPlayers;
use SpeedPuzzling\Web\Services\PrivateProfileAccess;
use SpeedPuzzling\Web\Services\PuzzleIntelligence\MspRatingFormula;
use SpeedPuzzling\Web\Services\PuzzleIntelligence\PuzzleIntelligenceRecalculator;
use SpeedPuzzling\Web\Value\RatingPortfolio;

readonly final class GetExportableSolvingTimes
{
    private const int RATING_PIECES_COUNT = PuzzleIntelligenceRecalculator::RATING_PIECES_COUNTS[0];

    public function __construct(
        private Connection $database,
        private string $uploadedAssetsBaseUrl,
        private HiddenPlayers $hiddenPlayers,
        private PrivateProfileAccess $privateProfileAccess,
        private ClockInterface $clock,
    ) {
    }

    /**
     * @return array<ExportableSolvingTime>
     * @throws PlayerNotFound
     */
    public function byPlayerId(string $playerId): array
    {
        if (Uuid::isValid($playerId) === false) {
            throw new PlayerNotFound();
        }

        $notHidden = $this->hiddenPlayers->sqlExclude('player.id');
        $notHiddenGroup = $this->hiddenPlayers->sqlExcludeTeam('pst.team');
        $playerIsPublic = $this->privateProfileAccess->sqlIsPublic('player');
        $memberIsPublic = $this->privateProfileAccess->sqlIsPublic('member_player');

        // player_rank and puzzle_total_solved read the puzzle page's leaderboard (PuzzleTimes): one row per player
        // (solo) or per exact pair/team with its best non-suspicious time, hidden players left out, private ones
        // only when the player may see them. A time ranks where it would stand among everybody else's best times,
        // so the best time gets exactly the leaderboard's rank; a suspicious time is not on the board, no rank.
        // The msp_rating columns (ResultRatingStatus) read what the rating batch stored: which of the player's solo
        // results of the rated piece count are its first-attempt / best-time entries, and whether each puzzle counts.
        // The rating aggregates per puzzle (MspRatingCalculator: earliest-fastest first attempt, fastest result), so
        // rating_times marks the one result that can be each entry; an entry is matched on its seconds as well, so a
        // result saved after the last run of the batch is "not computed yet", never another result's entry.
        $query = <<<SQL
WITH player_times AS (
    SELECT
        pst.id,
        pst.puzzle_id,
        pst.puzzling_type,
        pst.seconds_to_solve,
        pst.suspicious,
        CASE WHEN pst.puzzling_type = 'solo' THEN pst.player_id ELSE pst.puzzling_team_id END AS subject_id
    FROM puzzle_solving_time pst
    WHERE
        pst.player_id = :playerId
        OR (pst.team::jsonb -> 'puzzlers') @> jsonb_build_array(jsonb_build_object('player_id', CAST(:playerId AS UUID)))
),
ranked_boards AS (
    SELECT DISTINCT puzzle_id, puzzling_type
    FROM player_times
),
solo_best AS (
    SELECT pst.puzzle_id, pst.puzzling_type, pst.player_id AS subject_id, MIN(pst.seconds_to_solve) AS best_time
    FROM puzzle_solving_time pst
    INNER JOIN ranked_boards ON ranked_boards.puzzle_id = pst.puzzle_id AND ranked_boards.puzzling_type = pst.puzzling_type
    INNER JOIN player ON player.id = pst.player_id
    WHERE pst.puzzling_type = 'solo'
        AND pst.seconds_to_solve IS NOT NULL
        AND pst.suspicious = false
        AND ({$playerIsPublic} OR player.id = :playerId)
        {$notHidden}
    GROUP BY pst.puzzle_id, pst.puzzling_type, pst.player_id
),
group_best AS (
    SELECT pst.puzzle_id, pst.puzzling_type, pst.puzzling_team_id AS subject_id, MIN(pst.seconds_to_solve) AS best_time
    FROM puzzle_solving_time pst
    INNER JOIN ranked_boards ON ranked_boards.puzzle_id = pst.puzzle_id AND ranked_boards.puzzling_type = pst.puzzling_type
    WHERE pst.puzzling_type <> 'solo'
        AND pst.seconds_to_solve IS NOT NULL
        AND pst.suspicious = false
        AND pst.puzzling_team_id IS NOT NULL
        {$notHiddenGroup}
    GROUP BY pst.puzzle_id, pst.puzzling_type, pst.puzzling_team_id
),
board AS (
    SELECT puzzle_id, puzzling_type, subject_id, best_time FROM solo_best
    UNION ALL
    -- A pair/team is on the board when the player may see one of its members (a guest counts as visible)
    SELECT group_best.puzzle_id, group_best.puzzling_type, group_best.subject_id, group_best.best_time
    FROM group_best
    WHERE EXISTS (
        SELECT 1
        FROM puzzling_team_member member
        LEFT JOIN player member_player ON member_player.id = member.player_id
        WHERE member.team_id = group_best.subject_id
            AND (member_player.id IS NULL OR {$memberIsPublic} OR member_player.id = :playerId)
    )
),
-- One join + aggregate for all rows: a correlated count per row scans the whole board each time (21 s on prod)
standing AS (
    SELECT
        player_times.id,
        CASE
            WHEN player_times.seconds_to_solve IS NOT NULL AND player_times.suspicious = false
            THEN 1 + COUNT(*) FILTER (
                WHERE board.best_time < player_times.seconds_to_solve
                    AND board.subject_id IS DISTINCT FROM player_times.subject_id
            )
        END AS player_rank,
        COUNT(board.subject_id) AS puzzle_total_solved
    FROM player_times
    LEFT JOIN board ON board.puzzle_id = player_times.puzzle_id AND board.puzzling_type = player_times.puzzling_type
    GROUP BY player_times.id, player_times.seconds_to_solve, player_times.suspicious
),
rating_times AS (
    SELECT
        pst.id,
        pst.puzzle_id,
        pst.seconds_to_solve,
        COALESCE(pst.finished_at, pst.tracked_at) >= :ratingWindowStart AS in_window,
        ROW_NUMBER() OVER (
            PARTITION BY pst.puzzle_id
            ORDER BY pst.seconds_to_solve, COALESCE(pst.finished_at, pst.tracked_at), pst.id
        ) = 1 AS best_time_candidate,
        pst.first_attempt AND ROW_NUMBER() OVER (
            PARTITION BY pst.puzzle_id, pst.first_attempt
            ORDER BY pst.seconds_to_solve, COALESCE(pst.finished_at, pst.tracked_at), pst.id
        ) = 1 AS first_attempt_candidate
    FROM puzzle_solving_time pst
    INNER JOIN puzzle ON puzzle.id = pst.puzzle_id
    WHERE pst.player_id = :playerId
        AND puzzle.pieces_count = :ratingPiecesCount
        AND pst.puzzling_type = 'solo'
        AND pst.suspicious = false
        AND pst.seconds_to_solve IS NOT NULL
),
-- Whether a puzzle counts and its public first-try solvers (the rating's rule, an aggregate); puzzle_rating_times holds
-- the puzzles with 9+ of them, the others are counted here
rating_puzzles AS (
    SELECT
        ids.puzzle_id,
        COALESCE(prt.qualifies, false) AS qualifies,
        COALESCE(prt.solver_count, (
            SELECT COUNT(DISTINCT solver_time.player_id)
            FROM puzzle_solving_time solver_time
            INNER JOIN player solver ON solver.id = solver_time.player_id
            WHERE solver_time.puzzle_id = ids.puzzle_id
                AND solver_time.first_attempt = true
                AND solver_time.puzzling_type = 'solo'
                AND solver_time.suspicious = false
                AND solver_time.seconds_to_solve IS NOT NULL
                AND solver.is_private = false
        )) AS first_try_solvers
    FROM (SELECT DISTINCT puzzle_id FROM rating_times) ids
    LEFT JOIN puzzle_rating_times prt ON prt.puzzle_id = ids.puzzle_id
),
rating_status AS (
    SELECT
        rating_times.id,
        rating_times.in_window,
        rating_times.first_attempt_candidate OR rating_times.best_time_candidate AS candidate,
        fa.in_top AS counts_first_attempt,
        bt.in_top AS counts_best_time,
        rating_puzzles.qualifies,
        rating_puzzles.first_try_solvers
    FROM rating_times
    INNER JOIN rating_puzzles ON rating_puzzles.puzzle_id = rating_times.puzzle_id
    LEFT JOIN player_rating_entry fa ON rating_times.first_attempt_candidate
        AND fa.player_id = :playerId
        AND fa.pieces_count = :ratingPiecesCount
        AND fa.puzzle_id = rating_times.puzzle_id
        AND fa.portfolio = :firstAttemptPortfolio
        AND fa.seconds = rating_times.seconds_to_solve
    LEFT JOIN player_rating_entry bt ON rating_times.best_time_candidate
        AND bt.player_id = :playerId
        AND bt.pieces_count = :ratingPiecesCount
        AND bt.puzzle_id = rating_times.puzzle_id
        AND bt.portfolio = :bestTimePortfolio
        AND bt.seconds = rating_times.seconds_to_solve
)
SELECT
    pst.id AS time_id,
    puzzle.id AS puzzle_id,
    puzzle.name AS puzzle_name,
    manufacturer.name AS brand_name,
    puzzle.pieces_count,
    pst.seconds_to_solve,
    pst.finished_at,
    pst.tracked_at,
    pst.first_attempt,
    pst.unboxed,
    pst.finished_puzzle_photo,
    pst.comment,
    pst.puzzling_type AS solving_type,
    pst.puzzlers_count AS players_count,
    (
        SELECT string_agg(
            COALESCE(
                p.name,
                player_elem.player ->> 'player_name',
                CASE WHEN p.code IS NOT NULL THEN '#' || UPPER(p.code) ELSE NULL END
            ),
            ', '
            ORDER BY player_elem.ordinality
        )
        FROM json_array_elements(pst.team -> 'puzzlers') WITH ORDINALITY AS player_elem(player, ordinality)
        LEFT JOIN player p ON p.id = (player_elem.player ->> 'player_id')::UUID
        WHERE COALESCE(p.name, player_elem.player ->> 'player_name', p.code) IS NOT NULL
    ) AS team_members,
    CASE pst.puzzling_type
        WHEN 'solo' THEN ps.fastest_time_solo
        WHEN 'duo' THEN ps.fastest_time_duo
        WHEN 'team' THEN ps.fastest_time_team
    END AS puzzle_fastest_time,
    CASE pst.puzzling_type
        WHEN 'solo' THEN ps.average_time_solo
        WHEN 'duo' THEN ps.average_time_duo
        WHEN 'team' THEN ps.average_time_team
    END AS puzzle_average_time,
    standing.player_rank,
    standing.puzzle_total_solved,
    competition.id AS event_id,
    competition.name AS event_name,
    cs.id AS event_series_id,
    cs.name AS event_series_name,
    pst.suspicious,
    -- The exporting player's own setting: a private player has no MSP rating at all
    (SELECT owner.is_private FROM player owner WHERE owner.id = :playerId) AS rating_player_private,
    rating_status.in_window AS rating_in_window,
    rating_status.candidate AS rating_candidate,
    rating_status.counts_first_attempt AS rating_counts_first_attempt,
    rating_status.counts_best_time AS rating_counts_best_time,
    rating_status.qualifies AS rating_puzzle_qualifies,
    rating_status.first_try_solvers AS rating_puzzle_first_try_solvers
FROM puzzle_solving_time pst
INNER JOIN puzzle ON puzzle.id = pst.puzzle_id
INNER JOIN manufacturer ON manufacturer.id = puzzle.manufacturer_id
LEFT JOIN puzzle_statistics ps ON ps.puzzle_id = puzzle.id
INNER JOIN standing ON standing.id = pst.id
-- The event of the result (docs/features/events-page/high-frequency-series.md P22): a one-time event or an edition
-- (picked, or found for a series pick) with its series; a series pick without an edition (series-level) only its series
LEFT JOIN competition ON competition.id = pst.competition_id
LEFT JOIN competition_series cs ON cs.id = COALESCE(competition.series_id, pst.competition_series_id)
LEFT JOIN rating_status ON rating_status.id = pst.id
ORDER BY COALESCE(pst.finished_at, pst.tracked_at) DESC, tracked_at DESC
SQL;

        /**
         * @var array<array{
         *     time_id: string,
         *     puzzle_id: string,
         *     puzzle_name: string,
         *     brand_name: string,
         *     pieces_count: int,
         *     seconds_to_solve: null|int,
         *     finished_at: null|string,
         *     tracked_at: string,
         *     first_attempt: bool,
         *     unboxed: bool,
         *     finished_puzzle_photo: null|string,
         *     comment: null|string,
         *     solving_type: string,
         *     players_count: int,
         *     team_members: null|string,
         *     puzzle_fastest_time: null|int,
         *     puzzle_average_time: null|int,
         *     player_rank: null|int,
         *     puzzle_total_solved: int,
         *     event_id: null|string,
         *     event_name: null|string,
         *     event_series_id: null|string,
         *     event_series_name: null|string,
         *     suspicious: bool,
         *     rating_player_private: bool,
         *     rating_in_window: null|bool,
         *     rating_candidate: null|bool,
         *     rating_counts_first_attempt: null|bool,
         *     rating_counts_best_time: null|bool,
         *     rating_puzzle_qualifies: null|bool,
         *     rating_puzzle_first_try_solvers: null|int,
         * }> $data
         */
        $data = $this->database
            ->executeQuery($query, [
                'playerId' => $playerId,
                'ratingPiecesCount' => self::RATING_PIECES_COUNT,
                'ratingWindowStart' => MspRatingFormula::windowStart($this->clock->now())->format('Y-m-d H:i:s'),
                'firstAttemptPortfolio' => RatingPortfolio::FirstAttempt->value,
                'bestTimePortfolio' => RatingPortfolio::BestTime->value,
            ])
            ->fetchAllAssociative();

        return array_map(
            fn(array $row): ExportableSolvingTime => ExportableSolvingTime::fromDatabaseRow($row, $this->uploadedAssetsBaseUrl),
            $data,
        );
    }
}
