<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Query;

use Doctrine\DBAL\Connection;
use Psr\Clock\ClockInterface;
use SpeedPuzzling\Web\Exceptions\PlayerNotFound;
use SpeedPuzzling\Web\Results\PlayerCard;
use SpeedPuzzling\Web\Services\HiddenPlayers;

/**
 * The player card's numbers in one statement (docs/features/players-page/README.md): the precomputed
 * community_player_stats row, the MSP Rating and the skill tier on 500 pieces, three cheap facts (events,
 * sell/swap list, Instagram) and the 3 latest puzzles (docs/features/navigation CARD-03, a LATERAL - so profile + card
 * + context + the direct-messages check stay within CARD-06's 4 statements). The card's controller resolves the player
 * through GetPlayerProfile::byId() first; the blocklist fragment here is a second lock on the same door.
 *
 * The latest puzzles: the distinct puzzles of the player's results - solo, or any pair/team they belong to
 * (puzzling_team_member) - by their latest solve (COALESCE(finished_at, tracked_at)). Never a result awaiting
 * verification (docs/features/suspicious-times.md), never a secret puzzle (hide_until), its picture masked while it is
 * secret (hide_image_until); a pair/team result with a player hidden from the viewer is left out like on the profile.
 */
readonly final class GetPlayerCard
{
    // The piece count of the MSP Rating and the skill tier on the card - the same as the player header's tier
    public const int PIECES_COUNT = 500;

    /** The latest puzzles shown on the card */
    public const int RECENT_PUZZLES = 3;

    public function __construct(
        private Connection $database,
        private HiddenPlayers $hiddenPlayers,
        private ClockInterface $clock,
    ) {
    }

    /**
     * @param bool $withActivity the 12-month strip is members-only: anyone else does not even load it
     *
     * @throws PlayerNotFound
     */
    public function byPlayerId(string $playerId, bool $withActivity): PlayerCard
    {
        $notHidden = $this->hiddenPlayers->sqlExclude('p.id');
        $groupNotHidden = $this->hiddenPlayers->sqlExcludeTeam('pst.team');
        $recentPuzzles = self::RECENT_PUZZLES;
        $monthlySolves = $withActivity ? 's.monthly_solves' : 'NULL';
        $competesInEvents = GetPlayersDirectory::competesInEventsSql('p.id');

        // A player registered since the last cron run has no stats row yet: zeros. Rating and tier are left out for
        // a player who opted out of rankings. "Competes in events" is the directory's definition: connected to a
        // publicly visible event.
        $query = <<<SQL
SELECT
    COALESCE(s.solved_total, 0) AS solved_total,
    COALESCE(s.pieces_total, 0) AS pieces_total,
    COALESCE(s.favorites_count, 0) AS favorites_count,
    s.best500_seconds,
    s.best1000_seconds,
    s.last_solved_at,
    {$monthlySolves} AS monthly_solves,
    p.ranking_opted_out,
    CASE WHEN p.ranking_opted_out THEN NULL ELSE elo.elo_rating END AS elo_rating,
    CASE WHEN p.ranking_opted_out THEN NULL ELSE skill.skill_tier END AS skill_tier,
    {$competesInEvents} AS competes_in_events,
    EXISTS (SELECT 1 FROM sell_swap_list_item ssi WHERE ssi.player_id = p.id) AS swaps_puzzles,
    COALESCE(TRIM(p.instagram), '') <> '' AS on_instagram,
    recent.recent_puzzles
FROM player p
LEFT JOIN community_player_stats s ON s.player_id = p.id
LEFT JOIN player_elo elo ON elo.player_id = p.id AND elo.pieces_count = :piecesCount
LEFT JOIN player_skill skill ON skill.player_id = p.id AND skill.pieces_count = :piecesCount
LEFT JOIN LATERAL (
    SELECT json_agg(json_build_object(
        'puzzle_id', latest.puzzle_id,
        'name', latest.name,
        'manufacturer', latest.manufacturer,
        'pieces', latest.pieces_count,
        'image', CASE WHEN latest.hide_image_until IS NOT NULL AND latest.hide_image_until > :now::timestamp THEN NULL ELSE latest.image END,
        'image_ratio', CASE WHEN latest.hide_image_until IS NOT NULL AND latest.hide_image_until > :now::timestamp THEN NULL ELSE latest.image_ratio END
    ) ORDER BY latest.solved_at DESC, latest.puzzle_id) AS recent_puzzles
    FROM (
        SELECT puzzle.id AS puzzle_id, puzzle.name, m.name AS manufacturer, puzzle.pieces_count, puzzle.image,
            puzzle.image_ratio, puzzle.hide_image_until, solved.solved_at
        FROM (
            -- Each puzzle once, by its latest solve: the solo results, then the pair/team ones (two index scans - an
            -- OR of the two reads the whole table)
            SELECT results.puzzle_id, MAX(results.solved_at) AS solved_at
            FROM (
                SELECT pst.puzzle_id, COALESCE(pst.finished_at, pst.tracked_at) AS solved_at
                FROM puzzle_solving_time pst
                WHERE pst.player_id = p.id AND pst.puzzling_type = 'solo' AND pst.suspicious = false
                UNION ALL
                SELECT pst.puzzle_id, COALESCE(pst.finished_at, pst.tracked_at) AS solved_at
                FROM puzzling_team_member ptm
                INNER JOIN puzzle_solving_time pst ON pst.puzzling_team_id = ptm.team_id
                WHERE ptm.player_id = p.id AND pst.suspicious = false{$groupNotHidden}
            ) results
            GROUP BY results.puzzle_id
            -- Newest first already here: the puzzles are joined in this order and only until 3 of them qualify
            ORDER BY solved_at DESC, results.puzzle_id
        ) solved
        INNER JOIN puzzle ON puzzle.id = solved.puzzle_id
        INNER JOIN manufacturer m ON m.id = puzzle.manufacturer_id
        WHERE puzzle.hide_until IS NULL OR puzzle.hide_until <= :now::timestamp
        ORDER BY solved.solved_at DESC, solved.puzzle_id
        LIMIT {$recentPuzzles}
    ) latest
) recent ON true
WHERE p.id = :playerId
    {$notHidden}
SQL;

        $row = $this->database
            ->executeQuery($query, [
                'playerId' => $playerId,
                'piecesCount' => self::PIECES_COUNT,
                'now' => $this->clock->now()->format('Y-m-d H:i:s'),
            ])
            ->fetchAssociative();

        if ($row === false) {
            throw new PlayerNotFound();
        }

        /**
         * @var array{
         *     solved_total: int|string,
         *     pieces_total: int|string,
         *     favorites_count: int|string,
         *     best500_seconds: null|int|string,
         *     best1000_seconds: null|int|string,
         *     last_solved_at: null|string,
         *     monthly_solves: null|string,
         *     ranking_opted_out: bool,
         *     elo_rating: null|float|string,
         *     skill_tier: null|int|string,
         *     competes_in_events: bool,
         *     swaps_puzzles: bool,
         *     on_instagram: bool,
         *     recent_puzzles: null|string,
         * } $row
         */
        return PlayerCard::fromDatabaseRow($row);
    }
}
