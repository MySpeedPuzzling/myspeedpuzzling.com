<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Query;

use Doctrine\DBAL\Connection;
use SpeedPuzzling\Web\Exceptions\PlayerNotFound;
use SpeedPuzzling\Web\Results\PlayerCard;
use SpeedPuzzling\Web\Services\HiddenPlayers;

/**
 * The player card's numbers in one statement (docs/features/players-page/README.md): the precomputed
 * community_player_stats row, the MSP Rating and the skill tier on 500 pieces, and three cheap facts (events,
 * sell/swap list, Instagram). The card's controller resolves the player through GetPlayerProfile::byId() first; the
 * blocklist fragment here is a second lock on the same door.
 */
readonly final class GetPlayerCard
{
    // The piece count of the MSP Rating and the skill tier on the card - the same as the player header's tier
    public const int PIECES_COUNT = 500;

    public function __construct(
        private Connection $database,
        private HiddenPlayers $hiddenPlayers,
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
        $monthlySolves = $withActivity ? 's.monthly_solves' : 'NULL';

        // A player registered since the last cron run has no stats row yet: zeros. Rating and tier are left out for
        // a player who opted out of rankings. "Competes in events": joined an event, or logged a result at one.
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
    (
        EXISTS (SELECT 1 FROM competition_participant cp WHERE cp.player_id = p.id AND cp.deleted_at IS NULL)
        OR EXISTS (SELECT 1 FROM puzzle_solving_time pst WHERE pst.player_id = p.id AND pst.competition_id IS NOT NULL)
    ) AS competes_in_events,
    EXISTS (SELECT 1 FROM sell_swap_list_item ssi WHERE ssi.player_id = p.id) AS swaps_puzzles,
    COALESCE(TRIM(p.instagram), '') <> '' AS on_instagram
FROM player p
LEFT JOIN community_player_stats s ON s.player_id = p.id
LEFT JOIN player_elo elo ON elo.player_id = p.id AND elo.pieces_count = :piecesCount
LEFT JOIN player_skill skill ON skill.player_id = p.id AND skill.pieces_count = :piecesCount
WHERE p.id = :playerId
    {$notHidden}
SQL;

        $row = $this->database
            ->executeQuery($query, [
                'playerId' => $playerId,
                'piecesCount' => self::PIECES_COUNT,
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
         * } $row
         */
        return PlayerCard::fromDatabaseRow($row);
    }
}
