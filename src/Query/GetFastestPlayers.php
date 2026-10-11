<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Query;

use Doctrine\DBAL\Connection;
use Psr\Clock\ClockInterface;
use SpeedPuzzling\Web\Results\LeaderboardSlice;
use SpeedPuzzling\Web\Results\SolvedPuzzle;
use SpeedPuzzling\Web\Services\HiddenPlayers;
use SpeedPuzzling\Web\Services\TimesBoardGuestCache;
use SpeedPuzzling\Web\Services\TimesLeaderboardSql;
use SpeedPuzzling\Web\Value\CountryCode;
use SpeedPuzzling\Web\Value\SkillTier;

readonly final class GetFastestPlayers
{
    public function __construct(
        private Connection $database,
        private ClockInterface $clock,
        private HiddenPlayers $hiddenPlayers,
        private TimesBoardGuestCache $guestCache,
    ) {
    }

    /**
     * The top $limit players without a viewer - the rows of window()
     *
     * @return list<SolvedPuzzle>
     */
    public function perPiecesCount(int $piecesCount, int $limit, null|CountryCode $countryCode): array
    {
        return $this->window($piecesCount, $countryCode, $limit)->rows;
    }

    /**
     * One board in one statement: the top $limit players and the viewer's own row with $above rows above it and $below
     * rows below it (Value\LeaderboardWindow::rowsToLoad()), each with its shared rank, plus the viewer's position line
     * (docs/features/xp-levels/ux-polish/design-spec.md §4). The country narrows the list, so a rank is the rank in it.
     *
     * Each player's best time by a hash aggregate, ranked by a window over those bests (~6,600 rows for 500 pieces), then
     * one time with that best for the shown players only. It used to be DISTINCT ON (player) over every solo time of the
     * piece count, which sorted all of them first: 300-380 ms for 500 pieces (340k times) on the production copy, then
     * 55-80 ms with the aggregate (docs/features/seo/performance-2026-10.md). Equal bests share the rank and are ordered
     * by the player id; a player's equal best times show the earliest.
     *
     * @return LeaderboardSlice<SolvedPuzzle>
     */
    public function window(
        int $piecesCount,
        null|CountryCode $countryCode,
        int $limit,
        int $above = 0,
        int $below = 0,
        null|string $viewerPlayerId = null,
    ): LeaderboardSlice {
        $notHidden = $this->hiddenPlayers->sqlExclude('agg.entry_id');
        $countryCondition = $countryCode !== null ? 'AND pl.country = :countryCode' : '';
        $board = TimesLeaderboardSql::board('board.entry_id = CAST(:viewerPlayerId AS UUID)');
        $select = TimesLeaderboardSql::selectWithOwn('Detail');

        $query = <<<SQL
WITH BestTimes AS (
    -- Each player's best first (one hash aggregate over the solo times of the piece count, served by the partial
    -- index custom_pst_solo_board), the per-player filters only then - on ~6,500 grouped rows, not ~340k times
    SELECT agg.entry_id, agg.best_seconds
    FROM (
        SELECT
            pst.player_id AS entry_id,
            MIN(pst.seconds_to_solve) AS best_seconds
        FROM puzzle_solving_time pst
        INNER JOIN puzzle p ON p.id = pst.puzzle_id
        WHERE pst.puzzling_type = 'solo'
          AND p.pieces_count = :piecesCount
          AND pst.seconds_to_solve > 0
          AND pst.suspicious = false
        GROUP BY pst.player_id
    ) agg
    INNER JOIN player pl ON pl.id = agg.entry_id
    WHERE pl.is_private = false
      {$notHidden}
      {$countryCondition}
),
{$board},
FastestTimes AS (
    SELECT DISTINCT ON (pst.player_id)
        pst.id AS puzzle_solving_time_id,
        shown.rank,
        shown.position,
        shown.total,
        shown.closest_faster
    FROM shown
    INNER JOIN puzzle_solving_time pst ON pst.player_id = shown.entry_id
        AND pst.seconds_to_solve = shown.best_seconds
    INNER JOIN puzzle p ON p.id = pst.puzzle_id
    WHERE pst.puzzling_type = 'solo'
      AND p.pieces_count = :piecesCount
      AND pst.suspicious = false
    ORDER BY pst.player_id, COALESCE(pst.finished_at, pst.tracked_at), pst.id
),
Detail AS (
SELECT
    puzzle.id AS puzzle_id,
    puzzle.name AS puzzle_name,
    puzzle.alternative_names AS puzzle_alternative_names,
    CASE WHEN puzzle.hide_image_until IS NOT NULL AND puzzle.hide_image_until > :now::timestamp THEN NULL ELSE puzzle.image END AS puzzle_image,
    CASE WHEN puzzle.hide_image_until IS NOT NULL AND puzzle.hide_image_until > :now::timestamp THEN NULL ELSE puzzle.image_ratio END AS puzzle_image_ratio,
    puzzle.pieces_count,
    puzzle_solving_time.comment,
    puzzle_solving_time.tracked_at,
    puzzle_solving_time.finished_at,
    puzzle_solving_time.finished_puzzle_photo,
    puzzle_solving_time.seconds_to_solve AS time,
    player.name AS player_name,
    player.code AS player_code,
    player.country AS player_country,
    player.avatar AS player_avatar,
    player.id AS player_id,
    COUNT(puzzle_solving_time.puzzle_id) AS solved_times,
    manufacturer.name AS manufacturer_name,
    puzzle_solving_time.id AS time_id,
    puzzle.identification_number AS puzzle_identification_number,
    puzzle_solving_time.first_attempt,
    puzzle_solving_time.unboxed,
    is_private,
    competition.id AS competition_id,
    competition.shortcut AS competition_shortcut,
    competition.name AS competition_name,
    competition.slug AS competition_slug,
    cs.name AS competition_series_name,
    cs.shortcut AS competition_series_shortcut,
    cs.slug AS competition_series_slug,
    ps.skill_tier,
    player.ranking_opted_out,
    FastestTimes.rank,
    FastestTimes.position,
    FastestTimes.total,
    FastestTimes.closest_faster
FROM FastestTimes
INNER JOIN puzzle_solving_time ON puzzle_solving_time.id = FastestTimes.puzzle_solving_time_id
INNER JOIN puzzle ON puzzle.id = puzzle_solving_time.puzzle_id
INNER JOIN player ON player.id = puzzle_solving_time.player_id
INNER JOIN manufacturer ON manufacturer.id = puzzle.manufacturer_id
LEFT JOIN competition ON puzzle_solving_time.competition_id = competition.id
LEFT JOIN competition_series cs ON cs.id = COALESCE(competition.series_id, puzzle_solving_time.competition_series_id)
LEFT JOIN player_skill ps ON ps.player_id = player.id
GROUP BY player.id, puzzle.id, manufacturer.id, puzzle_solving_time.id, competition.id, cs.id, ps.skill_tier, FastestTimes.rank, FastestTimes.position, FastestTimes.total, FastestTimes.closest_faster
)
{$select}
SQL;

        // A guest (no viewer, nobody blocked) gets the same rows as every other guest - cached for a minute
        $data = $this->guestCache->rows(
            $viewerPlayerId === null && $notHidden === '',
            implode('|', ['solo', $piecesCount, $countryCode->name ?? '', $limit, $above, $below]),
            fn (): array => $this->database
                ->executeQuery($query, [
                    'piecesCount' => $piecesCount,
                    'limit' => max(1, $limit),
                    'above' => max(0, $above),
                    'below' => max(0, $below),
                    'viewerPlayerId' => $viewerPlayerId,
                    'milestones' => TimesLeaderboardSql::milestones(),
                    'countryCode' => $countryCode?->name,
                    'now' => $this->clock->now()->format('Y-m-d H:i:s'),
                ])
                ->fetchAllAssociative(),
        );

        return TimesLeaderboardSql::slice($data, static function (array $row): SolvedPuzzle {
            /** @var array{
             *     puzzle_id: string,
             *     puzzle_name: string,
             *     puzzle_alternative_names: string,
             *     puzzle_image: null|string,
             *     puzzle_image_ratio: null|string,
             *     time: int,
             *     player_name: null|string,
             *     player_code: string,
             *     player_country: null|string,
             *     player_avatar: null|string,
             *     player_id: string,
             *     solved_times: int,
             *     manufacturer_name: string,
             *     time_id: string,
             *     solved_times: int,
             *     finished_puzzle_photo: null|string,
             *     tracked_at: string,
             *     pieces_count: int,
             *     comment: null|string,
             *     puzzle_identification_number: null|string,
             *     finished_at: null|string,
             *     first_attempt: bool,
             *     unboxed: bool,
             *     is_private: bool,
             *     competition_id: null|string,
             *     competition_name: null|string,
             *     competition_shortcut: null|string,
             *     competition_slug: null|string,
             *     competition_series_name: null|string,
             *     competition_series_shortcut: null|string,
             *     competition_series_slug: null|string,
             *     skill_tier: null|int,
             *     ranking_opted_out: bool,
             * } $row
             */

            $row['skill_tier_name'] = $row['skill_tier'] !== null
                ? strtolower(SkillTier::from((int) $row['skill_tier'])->name)
                : null;

            return SolvedPuzzle::fromDatabaseRow($row);
        });
    }
}
