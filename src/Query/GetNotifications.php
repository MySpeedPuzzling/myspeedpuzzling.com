<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Query;

use Doctrine\DBAL\Connection;
use SpeedPuzzling\Web\Results\PlayerNotification;

readonly final class GetNotifications
{
    public function __construct(
        private Connection $database,
    ) {
    }

    public function countUnreadForPlayer(string $playerId): int
    {
        $query = <<<SQL
SELECT
    COUNT(id)
FROM notification
WHERE player_id = :playerId 
    AND read_at IS NULL
SQL;

        $count = $this->database
            ->executeQuery($query, [
                'playerId' => $playerId,
            ])
            ->fetchOne();
        assert(is_int($count));

        return $count;
    }

    /**
     * @return array<PlayerNotification>
     */
    public function forPlayer(string $playerId, int $limit, int $offset = 0): array
    {
        $query = <<<SQL
SELECT
    notified_at,
    read_at,
    notification.type AS notification_type,
    puzzle_solving_time.id as time_id,
    puzzle.id AS puzzle_id,
    puzzle.name AS puzzle_name,
    puzzle.alternative_name AS puzzle_alternative_name,
    puzzle.image AS puzzle_image,
    puzzle_solving_time.seconds_to_solve AS time,
    puzzle_solving_time.player_id AS target_player_id,
    player.name AS target_player_name,
    player.code AS target_player_code,
    player.country AS target_player_country,
    player.avatar AS target_player_avatar,
    pieces_count,
    manufacturer.name AS manufacturer_name,
    puzzle_solving_time.team ->> 'team_id' AS team_id,
    CASE
        WHEN puzzle_solving_time.team IS NOT NULL THEN JSON_AGG(
            JSON_BUILD_OBJECT(
                'player_id', player_elem.player ->> 'player_id',
                'player_name', COALESCE(p.name, player_elem.player ->> 'player_name'),
                'player_country', p.country,
                'is_private', p.is_private
            ) ORDER BY player_elem.ordinality
        )
    END AS players
FROM notification
LEFT JOIN puzzle_solving_time ON notification.target_solving_time_id = puzzle_solving_time.id
INNER JOIN puzzle ON puzzle.id = puzzle_solving_time.puzzle_id
INNER JOIN player ON puzzle_solving_time.player_id = player.id
INNER JOIN manufacturer ON manufacturer.id = puzzle.manufacturer_id
LEFT JOIN LATERAL json_array_elements(puzzle_solving_time.team -> 'puzzlers') WITH ORDINALITY AS player_elem(player, ordinality) ON true
LEFT JOIN player p ON p.id = (player_elem.player ->> 'player_id')::UUID
WHERE notification.player_id = :playerId
GROUP BY notification.id, puzzle_solving_time.id, puzzle.id, manufacturer.id, time, player.id
ORDER BY notification.notified_at DESC
LIMIT :limit
SQL;

        $data = $this->database
            ->executeQuery($query, [
                'playerId' => $playerId,
                'limit' => $limit,
                'offset' => $offset,
            ])
            ->fetchAllAssociative();

        return array_map(static function (array $row): PlayerNotification {
            /**
             * @var array{
             *     notified_at: string,
             *     read_at: null|string,
             *     notification_type: string,
             *     target_player_id: string,
             *     target_player_name: null|string,
             *     target_player_code: string,
             *     target_player_avatar: null|string,
             *     target_player_country: null|string,
             *     puzzle_id: string,
             *     puzzle_name: string,
             *     puzzle_alternative_name: null|string,
             *     manufacturer_name: string,
             *     pieces_count: int,
             *     time: int,
             *     puzzle_image: null|string,
             *     team_id: null|string,
             *     players: null|string,
             * } $row
             */

            return PlayerNotification::fromDatabaseRow($row);
        }, $data);
    }
}
