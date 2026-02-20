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

    public function getOldestUnreadNotifiedAtForPlayer(string $playerId): null|\DateTimeImmutable
    {
        $query = <<<SQL
SELECT MIN(notified_at)
FROM notification
WHERE player_id = :playerId
    AND read_at IS NULL
SQL;

        $result = $this->database
            ->executeQuery($query, [
                'playerId' => $playerId,
            ])
            ->fetchOne();

        if (!is_string($result)) {
            return null;
        }

        return new \DateTimeImmutable($result);
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
        NULL::int AS lending_pieces_count,
        -- Puzzle report fields (NULL for puzzle solving notifications)
        NULL::uuid AS change_request_id,
        NULL::uuid AS change_request_puzzle_id,
        NULL::varchar AS change_request_puzzle_name,
        NULL::varchar AS change_request_puzzle_image,
        NULL::varchar AS change_request_rejection_reason,
        NULL::uuid AS merge_request_id,
        NULL::uuid AS merge_request_puzzle_id,
        NULL::varchar AS merge_request_puzzle_name,
        NULL::varchar AS merge_request_puzzle_image,
        NULL::varchar AS merge_request_rejection_reason,
        -- Rating notification fields (NULL for puzzle solving notifications)
        NULL::uuid AS sold_swapped_item_id,
        NULL::varchar AS rating_puzzle_name,
        NULL::varchar AS rating_puzzle_image,
        NULL::varchar AS rating_other_player_name,
        NULL::uuid AS rating_other_player_id,
        -- Conversation request fields (NULL for puzzle solving notifications)
        NULL::uuid AS conversation_id,
        NULL::uuid AS conversation_initiator_id,
        NULL::varchar AS conversation_initiator_name,
        NULL::varchar AS conversation_initiator_avatar,
        NULL::boolean AS conversation_is_marketplace,
        NULL::varchar AS conversation_puzzle_name,
        NULL::varchar AS conversation_puzzle_image
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
        puzzle.pieces_count AS lending_pieces_count,
        -- Puzzle report fields (NULL for lending notifications)
        NULL::uuid AS change_request_id,
        NULL::uuid AS change_request_puzzle_id,
        NULL::varchar AS change_request_puzzle_name,
        NULL::varchar AS change_request_puzzle_image,
        NULL::varchar AS change_request_rejection_reason,
        NULL::uuid AS merge_request_id,
        NULL::uuid AS merge_request_puzzle_id,
        NULL::varchar AS merge_request_puzzle_name,
        NULL::varchar AS merge_request_puzzle_image,
        NULL::varchar AS merge_request_rejection_reason,
        -- Rating notification fields (NULL for lending notifications)
        NULL::uuid AS sold_swapped_item_id,
        NULL::varchar AS rating_puzzle_name,
        NULL::varchar AS rating_puzzle_image,
        NULL::varchar AS rating_other_player_name,
        NULL::uuid AS rating_other_player_id,
        -- Conversation request fields (NULL for lending notifications)
        NULL::uuid AS conversation_id,
        NULL::uuid AS conversation_initiator_id,
        NULL::varchar AS conversation_initiator_name,
        NULL::varchar AS conversation_initiator_avatar,
        NULL::boolean AS conversation_is_marketplace,
        NULL::varchar AS conversation_puzzle_name,
        NULL::varchar AS conversation_puzzle_image
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

    UNION ALL

    -- Puzzle change request notifications
    SELECT
        notification.notified_at,
        notification.read_at,
        notification.type AS notification_type,
        -- Puzzle solving fields (NULL)
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
        -- Lending fields (NULL)
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
        NULL::int AS lending_pieces_count,
        -- Puzzle change request fields
        pcr.id AS change_request_id,
        puzzle.id AS change_request_puzzle_id,
        puzzle.name AS change_request_puzzle_name,
        puzzle.image AS change_request_puzzle_image,
        pcr.rejection_reason AS change_request_rejection_reason,
        NULL::uuid AS merge_request_id,
        NULL::uuid AS merge_request_puzzle_id,
        NULL::varchar AS merge_request_puzzle_name,
        NULL::varchar AS merge_request_puzzle_image,
        NULL::varchar AS merge_request_rejection_reason,
        -- Rating notification fields (NULL for change request notifications)
        NULL::uuid AS sold_swapped_item_id,
        NULL::varchar AS rating_puzzle_name,
        NULL::varchar AS rating_puzzle_image,
        NULL::varchar AS rating_other_player_name,
        NULL::uuid AS rating_other_player_id,
        -- Conversation request fields (NULL for change request notifications)
        NULL::uuid AS conversation_id,
        NULL::uuid AS conversation_initiator_id,
        NULL::varchar AS conversation_initiator_name,
        NULL::varchar AS conversation_initiator_avatar,
        NULL::boolean AS conversation_is_marketplace,
        NULL::varchar AS conversation_puzzle_name,
        NULL::varchar AS conversation_puzzle_image
    FROM notification
    INNER JOIN puzzle_change_request pcr ON notification.target_change_request_id = pcr.id
    INNER JOIN puzzle ON pcr.puzzle_id = puzzle.id
    WHERE notification.player_id = :playerId
        AND notification.target_change_request_id IS NOT NULL

    UNION ALL

    -- Puzzle merge request notifications
    SELECT
        notification.notified_at,
        notification.read_at,
        notification.type AS notification_type,
        -- Puzzle solving fields (NULL)
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
        -- Lending fields (NULL)
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
        NULL::int AS lending_pieces_count,
        -- Puzzle merge request fields
        NULL::uuid AS change_request_id,
        NULL::uuid AS change_request_puzzle_id,
        NULL::varchar AS change_request_puzzle_name,
        NULL::varchar AS change_request_puzzle_image,
        NULL::varchar AS change_request_rejection_reason,
        pmr.id AS merge_request_id,
        source_puzzle.id AS merge_request_puzzle_id,
        source_puzzle.name AS merge_request_puzzle_name,
        source_puzzle.image AS merge_request_puzzle_image,
        pmr.rejection_reason AS merge_request_rejection_reason,
        -- Rating notification fields (NULL for merge request notifications)
        NULL::uuid AS sold_swapped_item_id,
        NULL::varchar AS rating_puzzle_name,
        NULL::varchar AS rating_puzzle_image,
        NULL::varchar AS rating_other_player_name,
        NULL::uuid AS rating_other_player_id,
        -- Conversation request fields (NULL for merge request notifications)
        NULL::uuid AS conversation_id,
        NULL::uuid AS conversation_initiator_id,
        NULL::varchar AS conversation_initiator_name,
        NULL::varchar AS conversation_initiator_avatar,
        NULL::boolean AS conversation_is_marketplace,
        NULL::varchar AS conversation_puzzle_name,
        NULL::varchar AS conversation_puzzle_image
    FROM notification
    INNER JOIN puzzle_merge_request pmr ON notification.target_merge_request_id = pmr.id
    INNER JOIN puzzle source_puzzle ON pmr.source_puzzle_id = source_puzzle.id
    WHERE notification.player_id = :playerId
        AND notification.target_merge_request_id IS NOT NULL

    UNION ALL

    -- Transaction rating notifications
    SELECT
        notification.notified_at,
        notification.read_at,
        notification.type AS notification_type,
        -- Puzzle solving fields (NULL)
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
        -- Lending fields (NULL)
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
        NULL::int AS lending_pieces_count,
        -- Puzzle report fields (NULL)
        NULL::uuid AS change_request_id,
        NULL::uuid AS change_request_puzzle_id,
        NULL::varchar AS change_request_puzzle_name,
        NULL::varchar AS change_request_puzzle_image,
        NULL::varchar AS change_request_rejection_reason,
        NULL::uuid AS merge_request_id,
        NULL::uuid AS merge_request_puzzle_id,
        NULL::varchar AS merge_request_puzzle_name,
        NULL::varchar AS merge_request_puzzle_image,
        NULL::varchar AS merge_request_rejection_reason,
        -- Rating notification fields
        ssi.id AS sold_swapped_item_id,
        puzzle.name AS rating_puzzle_name,
        puzzle.image AS rating_puzzle_image,
        CASE
            WHEN ssi.seller_id = notification.player_id THEN COALESCE(buyer.name, buyer.code)
            ELSE COALESCE(seller.name, seller.code)
        END AS rating_other_player_name,
        CASE
            WHEN ssi.seller_id = notification.player_id THEN buyer.id
            ELSE seller.id
        END AS rating_other_player_id,
        -- Conversation request fields (NULL for rating notifications)
        NULL::uuid AS conversation_id,
        NULL::uuid AS conversation_initiator_id,
        NULL::varchar AS conversation_initiator_name,
        NULL::varchar AS conversation_initiator_avatar,
        NULL::boolean AS conversation_is_marketplace,
        NULL::varchar AS conversation_puzzle_name,
        NULL::varchar AS conversation_puzzle_image
    FROM notification
    INNER JOIN sold_swapped_item ssi ON notification.target_sold_swapped_item_id = ssi.id
    INNER JOIN puzzle ON ssi.puzzle_id = puzzle.id
    INNER JOIN player seller ON ssi.seller_id = seller.id
    LEFT JOIN player buyer ON ssi.buyer_player_id = buyer.id
    WHERE notification.player_id = :playerId
        AND notification.target_sold_swapped_item_id IS NOT NULL

    UNION ALL

    -- Conversation request notifications
    SELECT
        notification.notified_at,
        notification.read_at,
        notification.type AS notification_type,
        -- Puzzle solving fields (NULL)
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
        -- Lending fields (NULL)
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
        NULL::int AS lending_pieces_count,
        -- Puzzle report fields (NULL)
        NULL::uuid AS change_request_id,
        NULL::uuid AS change_request_puzzle_id,
        NULL::varchar AS change_request_puzzle_name,
        NULL::varchar AS change_request_puzzle_image,
        NULL::varchar AS change_request_rejection_reason,
        NULL::uuid AS merge_request_id,
        NULL::uuid AS merge_request_puzzle_id,
        NULL::varchar AS merge_request_puzzle_name,
        NULL::varchar AS merge_request_puzzle_image,
        NULL::varchar AS merge_request_rejection_reason,
        -- Rating notification fields (NULL)
        NULL::uuid AS sold_swapped_item_id,
        NULL::varchar AS rating_puzzle_name,
        NULL::varchar AS rating_puzzle_image,
        NULL::varchar AS rating_other_player_name,
        NULL::uuid AS rating_other_player_id,
        -- Conversation request fields
        conv.id AS conversation_id,
        initiator.id AS conversation_initiator_id,
        COALESCE(initiator.name, initiator.code) AS conversation_initiator_name,
        initiator.avatar AS conversation_initiator_avatar,
        (conv.sell_swap_list_item_id IS NOT NULL) AS conversation_is_marketplace,
        conv_puzzle.name AS conversation_puzzle_name,
        conv_puzzle.image AS conversation_puzzle_image
    FROM notification
    INNER JOIN conversation conv ON notification.target_conversation_id = conv.id
    INNER JOIN player initiator ON conv.initiator_id = initiator.id
    LEFT JOIN puzzle conv_puzzle ON conv.puzzle_id = conv_puzzle.id
    WHERE notification.player_id = :playerId
        AND notification.target_conversation_id IS NOT NULL
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
