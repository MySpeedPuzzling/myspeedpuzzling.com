<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Query;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;
use SpeedPuzzling\Web\Results\PlayersDirectoryCard;
use SpeedPuzzling\Web\Results\PlayersDirectoryPage;
use SpeedPuzzling\Web\Services\HiddenPlayers;
use SpeedPuzzling\Web\Value\PlayersDirectoryCriteria;
use SpeedPuzzling\Web\Value\PlayersDirectorySort;

/**
 * The directory of puzzlers - "Browse all" and the country pages (docs/features/players-page/README.md). One
 * statement: the numbers come from the precomputed community_player_stats, never from puzzle_solving_time.
 *
 * - Public profiles only, for everybody (like every Players page list), and never the players the viewer has hidden.
 * - "Competes in events" = a participant of a publicly visible event. That is a small table read through its
 *   player_id index; results linked to an event would also need the team JSON of every pair/team time.
 * - The inner query filters, orders and cuts the page; the two EXISTS for the chips then run for the shown cards only.
 */
readonly final class GetPlayersDirectory
{
    public function __construct(
        private Connection $database,
        private HiddenPlayers $hiddenPlayers,
    ) {
    }

    public function search(PlayersDirectoryCriteria $criteria): PlayersDirectoryPage
    {
        $parameters = ['limit' => $criteria->limit];
        $types = ['limit' => ParameterType::INTEGER];
        $conditions = [];

        if ($criteria->scope->country !== null) {
            $conditions[] = 'p.country = :country';
            $parameters['country'] = $criteria->scope->country->name;
        }

        if ($criteria->activeThisMonth) {
            $conditions[] = 's.solves_this_month > 0';
        }

        if ($criteria->competesInEvents) {
            $conditions[] = self::competesInEventsSql('p.id');
        }

        if ($criteria->swapsPuzzles) {
            $conditions[] = self::swapsPuzzlesSql('p.id');
        }

        if ($criteria->onInstagram) {
            $conditions[] = self::onInstagramSql();
        }

        $filters = implode('', array_map(static fn (string $condition): string => "\n        AND {$condition}", $conditions));
        $notHidden = $this->hiddenPlayers->sqlExclude('p.id');
        $orderBy = self::orderBySql($criteria->sort);
        $onInstagram = self::onInstagramSql();
        $competesInEvents = self::competesInEventsSql('d.player_id');
        $swapsPuzzles = self::swapsPuzzlesSql('d.player_id');

        $query = <<<SQL
SELECT
    d.player_id,
    d.player_code,
    d.player_name,
    d.player_country,
    d.player_avatar,
    d.solves_this_month,
    d.best500_seconds,
    d.favorites_count,
    d.on_instagram,
    d.total,
    {$competesInEvents} AS competes_in_events,
    {$swapsPuzzles} AS swaps_puzzles
FROM (
    SELECT
        p.id AS player_id,
        p.code AS player_code,
        p.name AS player_name,
        p.country AS player_country,
        p.avatar AS player_avatar,
        COALESCE(s.solves_this_month, 0) AS solves_this_month,
        s.best500_seconds,
        COALESCE(s.favorites_count, 0) AS favorites_count,
        {$onInstagram} AS on_instagram,
        ROW_NUMBER() OVER (ORDER BY {$orderBy}) AS position,
        COUNT(*) OVER () AS total
    FROM player p
    LEFT JOIN community_player_stats s ON s.player_id = p.id
    WHERE p.is_private = false{$filters}
        {$notHidden}
    ORDER BY position
    LIMIT :limit
) d
ORDER BY d.position
SQL;

        $rows = $this->database
            ->executeQuery($query, $parameters, $types)
            ->fetchAllAssociative();

        $cards = [];
        $total = 0;

        foreach ($rows as $row) {
            /**
             * @var array{
             *     player_id: string,
             *     player_code: string,
             *     player_name: null|string,
             *     player_country: null|string,
             *     player_avatar: null|string,
             *     solves_this_month: int|string,
             *     best500_seconds: null|int|string,
             *     favorites_count: int|string,
             *     on_instagram: bool,
             *     total: int|string,
             *     competes_in_events: bool,
             *     swaps_puzzles: bool,
             * } $row
             */
            $total = (int) $row['total'];
            unset($row['total']);
            $cards[] = PlayersDirectoryCard::fromDatabaseRow($row);
        }

        return new PlayersDirectoryPage($cards, $total);
    }

    /**
     * Aliases: `p` = player, `s` = community_player_stats (LEFT JOINed: a player registered since the last stats run
     * has no row yet). Every order ends with the id, so a longer page keeps the cards of the shorter one in place.
     */
    private static function orderBySql(PlayersDirectorySort $sort): string
    {
        return match ($sort) {
            PlayersDirectorySort::Active => 'COALESCE(s.solves_this_month, 0) DESC, COALESCE(s.pieces_this_month, 0) DESC, s.last_solved_at DESC NULLS LAST, p.id',
            PlayersDirectorySort::Recent => 's.last_solved_at DESC NULLS LAST, p.id',
            PlayersDirectorySort::Newest => 'p.registered_at DESC, p.id',
            PlayersDirectorySort::Followed => 'COALESCE(s.favorites_count, 0) DESC, s.last_solved_at DESC NULLS LAST, p.id',
            PlayersDirectorySort::Name => 'LOWER(COALESCE(p.name, p.code)), p.id',
        };
    }

    private static function competesInEventsSql(string $playerColumn): string
    {
        $visible = IsCompetitionPubliclyVisible::SQL_CONDITION;

        return <<<SQL
EXISTS (
            SELECT 1
            FROM competition_participant cp
            JOIN competition c ON c.id = cp.competition_id
            LEFT JOIN competition_series cs ON cs.id = c.series_id
            WHERE cp.player_id = {$playerColumn}
                AND cp.deleted_at IS NULL
                AND {$visible}
        )
SQL;
    }

    private static function swapsPuzzlesSql(string $playerColumn): string
    {
        return "EXISTS (SELECT 1 FROM sell_swap_list_item ssli WHERE ssli.player_id = {$playerColumn})";
    }

    private static function onInstagramSql(): string
    {
        return "COALESCE(TRIM(p.instagram), '') <> ''";
    }
}
