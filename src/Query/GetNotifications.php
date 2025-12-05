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
SELECT * FROM (
    -- Puzzle solving notifications
    SELECT
        notification.notified_at,
        notification.read_at,
        notification.type AS notification_type,
        puzzle_solving_time.player_id AS target_player_id,
        player.name AS target_player_name,
        player.code AS target_player_code,
        player.country AS target_player_country,
        player.avatar AS target_player_avatar,
        puzzle.id AS puzzle_id,
        puzzle.name AS puzzle_name,
        puzzle.alternative_name AS puzzle_alternative_name,
        manufacturer.name AS manufacturer_name,
        puzzle.pieces_count,
        puzzle_solving_time.seconds_to_solve AS time,
        puzzle.image AS puzzle_image,
        puzzle_solving_time.team ->> 'team_id' AS team_id,
        CASE
            WHEN puzzle_solving_time.team IS NOT NULL THEN JSON_AGG(
                JSON_BUILD_OBJECT(
                    'player_id', player_elem.player ->> 'player_id',
                    'player_name', COALESCE(p.name, player_elem.player ->> 'player_name'),
                    'player_code', p.code,
                    'player_country', p.country,
                    'is_private', p.is_private
                ) ORDER BY player_elem.ordinality
            )
        END AS players,
        -- Lending fields (NULL for puzzle solving notifications)
        NULL::uuid AS transfer_id,
        NULL::varchar AS transfer_type,
        NULL::uuid AS from_player_id,
        NULL::varchar AS from_player_name,
        NULL::varchar AS from_player_avatar,
        NULL::uuid AS to_player_id,
        NULL::varchar AS to_player_name,
        NULL::varchar AS to_player_avatar,
        NULL::uuid AS owner_player_id,
        NULL::varchar AS owner_player_name,
        NULL::uuid AS lending_puzzle_id,
        NULL::varchar AS lending_puzzle_name,
        NULL::varchar AS lending_puzzle_image,
        NULL::varchar AS lending_manufacturer_name,
        NULL::int AS lending_pieces_count
    FROM notification
    LEFT JOIN puzzle_solving_time ON notification.target_solving_time_id = puzzle_solving_time.id
    INNER JOIN puzzle ON puzzle.id = puzzle_solving_time.puzzle_id
    INNER JOIN player ON puzzle_solving_time.player_id = player.id
    INNER JOIN manufacturer ON manufacturer.id = puzzle.manufacturer_id
    LEFT JOIN LATERAL json_array_elements(puzzle_solving_time.team -> 'puzzlers') WITH ORDINALITY AS player_elem(player, ordinality) ON true
    LEFT JOIN player p ON p.id = (player_elem.player ->> 'player_id')::UUID
    WHERE notification.player_id = :playerId
        AND notification.target_solving_time_id IS NOT NULL
    GROUP BY notification.id, puzzle_solving_time.id, puzzle.id, manufacturer.id, player.id

    UNION ALL

    -- Lending notifications
    SELECT
        notification.notified_at,
        notification.read_at,
        notification.type AS notification_type,
        -- Puzzle solving fields (NULL for lending notifications)
        NULL::uuid AS target_player_id,
        NULL::varchar AS target_player_name,
        NULL::varchar AS target_player_code,
        NULL::varchar AS target_player_country,
        NULL::varchar AS target_player_avatar,
        NULL::uuid AS puzzle_id,
        NULL::varchar AS puzzle_name,
        NULL::varchar AS puzzle_alternative_name,
        NULL::varchar AS manufacturer_name,
        NULL::int AS pieces_count,
        NULL::int AS time,
        NULL::varchar AS puzzle_image,
        NULL::varchar AS team_id,
        NULL::json AS players,
        -- Lending fields
        lpt.id AS transfer_id,
        lpt.transfer_type,
        lpt.from_player_id,
        COALESCE(from_player.name, from_player.code, lpt.from_player_name) AS from_player_name,
        from_player.avatar AS from_player_avatar,
        lpt.to_player_id,
        COALESCE(to_player.name, to_player.code, lpt.to_player_name) AS to_player_name,
        to_player.avatar AS to_player_avatar,
        lp.owner_player_id,
        COALESCE(owner_player.name, owner_player.code, lp.owner_name) AS owner_player_name,
        puzzle.id AS lending_puzzle_id,
        puzzle.name AS lending_puzzle_name,
        puzzle.image AS lending_puzzle_image,
        manufacturer.name AS lending_manufacturer_name,
        puzzle.pieces_count AS lending_pieces_count
    FROM notification
    INNER JOIN lent_puzzle_transfer lpt ON notification.target_transfer_id = lpt.id
    INNER JOIN lent_puzzle lp ON lpt.lent_puzzle_id = lp.id
    INNER JOIN puzzle ON lp.puzzle_id = puzzle.id
    INNER JOIN manufacturer ON puzzle.manufacturer_id = manufacturer.id
    LEFT JOIN player from_player ON lpt.from_player_id = from_player.id
    LEFT JOIN player to_player ON lpt.to_player_id = to_player.id
    LEFT JOIN player owner_player ON lp.owner_player_id = owner_player.id
    WHERE notification.player_id = :playerId
        AND notification.target_transfer_id IS NOT NULL
) AS combined_notifications
ORDER BY notified_at DESC
LIMIT :limit
OFFSET :offset
SQL;

        $data = $this->database
            ->executeQuery($query, [
                'playerId' => $playerId,
                'limit' => $limit,
                'offset' => $offset,
            ])
            ->fetchAllAssociative();

        return array_map(static function (array $row): PlayerNotification {
            return PlayerNotification::fromDatabaseRow($row);
        }, $data);
    }
}
