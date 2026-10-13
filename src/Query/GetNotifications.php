<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Query;

use Doctrine\DBAL\Connection;
use Psr\Clock\ClockInterface;
use SpeedPuzzling\Web\Results\PlayerNotification;
use SpeedPuzzling\Web\Services\HiddenPlayers;
use SpeedPuzzling\Web\Services\PrivateProfileAccess;
use SpeedPuzzling\Web\Value\NotificationType;

readonly final class GetNotifications
{
    public function __construct(
        private Connection $database,
        private PrivateProfileAccess $privateProfileAccess,
        private ClockInterface $clock,
        private HiddenPlayers $hiddenPlayers,
    ) {
    }

    public function getOldestUnreadNotifiedAtForPlayer(string $playerId): null|\DateTimeImmutable
    {
        $notHidden = $this->unreadNotHidden();
        $listed = self::sqlListed();

        $query = <<<SQL
SELECT MIN(notified_at)
FROM notification
WHERE player_id = :playerId
    AND read_at IS NULL
    {$listed}
    {$notHidden}
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
     * Every row the Inbox badge's For you part counts is listed by one branch here - a branch never inner-joins
     * something that can go away while the notification stays (a merged-away puzzle, an anonymised player, a brand)
     * and hides exactly what HiddenPlayers::sqlNotificationNotHiddenFor() hides (GetInboxBadgeTest pins the parity).
     *
     * @return array<PlayerNotification>
     */
    public function forPlayer(string $playerId, int $limit, int $offset = 0): array
    {
        // Lending and rating notifications are left alone: bilateral history stays readable
        // A private solver stays masked for a reader who is not on their allow list. The editor of a
        // shared time is named to the group on purpose (docs/features/group-time-editing.md)
        $targetIsPrivate = "(notification.actor_player_id IS NULL AND {$this->privateProfileAccess->sqlIsPrivate('player')})";
        $solvingTimeNotHidden = $this->hiddenPlayers->sqlExclude('player.id')
            . $this->hiddenPlayers->sqlExcludeTeam('puzzle_solving_time.team');
        $initiatorNotHidden = $this->hiddenPlayers->sqlExclude('initiator.id');
        $actorNotHidden = $this->hiddenPlayers->sqlExclude('actor.id');
        $listed = self::sqlListed();

        $query = <<<SQL
SELECT * FROM (
    -- Puzzle solving notifications
    SELECT
        notification.notified_at,
        notification.read_at,
        notification.type AS notification_type,
        player.id AS target_player_id,
        CASE WHEN {$targetIsPrivate} THEN NULL ELSE player.name END AS target_player_name,
        player.code AS target_player_code,
        CASE WHEN {$targetIsPrivate} THEN NULL ELSE player.country END AS target_player_country,
        CASE WHEN {$targetIsPrivate} THEN NULL ELSE player.avatar END AS target_player_avatar,
        {$targetIsPrivate} AS target_player_is_private,
        puzzle.id AS puzzle_id,
        puzzle.name AS puzzle_name,
        manufacturer.name AS manufacturer_name,
        puzzle.pieces_count,
        puzzle_solving_time.seconds_to_solve AS time,
        puzzle_solving_time.first_attempt,
        puzzle_solving_time.unboxed,
        CASE WHEN puzzle.hide_image_until IS NOT NULL AND puzzle.hide_image_until > :now::timestamp THEN NULL ELSE puzzle.image END AS puzzle_image,
        CASE WHEN puzzle.hide_image_until IS NOT NULL AND puzzle.hide_image_until > :now::timestamp THEN NULL ELSE puzzle.image_ratio END AS puzzle_image_ratio,
        puzzle_solving_time.puzzling_team_id::varchar AS team_id,
        NULL::varchar AS team_name,
        NULL::uuid AS guest_link_request_id,
        NULL::varchar AS guest_link_guest_name,
        CASE
            WHEN puzzle_solving_time.team IS NOT NULL THEN JSON_AGG(
                JSON_BUILD_OBJECT(
                    'player_id', player_elem.player ->> 'player_id',
                    'player_name', COALESCE(p.name, player_elem.player ->> 'player_name'),
                    'player_code', p.code,
                    'player_country', p.country,
                    'is_private', {$this->privateProfileAccess->sqlIsPrivate('p')}
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
        NULL::numeric AS lending_puzzle_image_ratio,
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
        NULL::varchar AS conversation_puzzle_image,
        -- Official results fields (NULL)
        NULL::uuid AS round_id,
        NULL::varchar AS round_name,
        NULL::varchar AS round_slug,
        NULL::varchar AS round_competition_name,
        NULL::varchar AS round_competition_slug,
        NULL::varchar AS round_series_slug,
        -- The player card's context of the row (docs/features/navigation CARD-01b)
        puzzle_solving_time.id AS target_time_id
    FROM notification
    LEFT JOIN puzzle_solving_time ON notification.target_solving_time_id = puzzle_solving_time.id
    INNER JOIN puzzle ON puzzle.id = puzzle_solving_time.puzzle_id
    -- The "target player" is who the notification is about: the group member who edited the time
    -- when there is an actor, otherwise whoever tracked it
    INNER JOIN player ON player.id = COALESCE(notification.actor_player_id, puzzle_solving_time.player_id)
    -- LEFT: a puzzle may have no brand (puzzle.manufacturer_id is nullable) - the row is counted, so it is listed
    LEFT JOIN manufacturer ON manufacturer.id = puzzle.manufacturer_id
    LEFT JOIN LATERAL json_array_elements(puzzle_solving_time.team -> 'puzzlers') WITH ORDINALITY AS player_elem(player, ordinality) ON true
    LEFT JOIN player p ON p.id = (player_elem.player ->> 'player_id')::UUID
    WHERE notification.player_id = :playerId
        AND notification.target_solving_time_id IS NOT NULL
        {$listed}
        {$solvingTimeNotHidden}
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
        NULL::boolean AS target_player_is_private,
        NULL::uuid AS puzzle_id,
        NULL::varchar AS puzzle_name,
        NULL::varchar AS manufacturer_name,
        NULL::int AS pieces_count,
        NULL::int AS time,
        NULL::boolean AS first_attempt,
        NULL::boolean AS unboxed,
        NULL::varchar AS puzzle_image,
        NULL::numeric AS puzzle_image_ratio,
        NULL::varchar AS team_id,
        NULL::varchar AS team_name,
        NULL::uuid AS guest_link_request_id,
        NULL::varchar AS guest_link_guest_name,
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
        COALESCE(lpt.owner_player_id, lp.owner_player_id) AS owner_player_id,
        COALESCE(owner_player.name, owner_player.code, lpt.owner_name, lp.owner_name) AS owner_player_name,
        puzzle.id AS lending_puzzle_id,
        puzzle.name AS lending_puzzle_name,
        CASE WHEN puzzle.hide_image_until IS NOT NULL AND puzzle.hide_image_until > :now::timestamp THEN NULL ELSE puzzle.image END AS lending_puzzle_image,
        CASE WHEN puzzle.hide_image_until IS NOT NULL AND puzzle.hide_image_until > :now::timestamp THEN NULL ELSE puzzle.image_ratio END AS lending_puzzle_image_ratio,
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
        NULL::varchar AS conversation_puzzle_image,
        -- Official results fields (NULL)
        NULL::uuid AS round_id,
        NULL::varchar AS round_name,
        NULL::varchar AS round_slug,
        NULL::varchar AS round_competition_name,
        NULL::varchar AS round_competition_slug,
        NULL::varchar AS round_series_slug,
        NULL::uuid AS target_time_id
    FROM notification
    INNER JOIN lent_puzzle_transfer lpt ON notification.target_transfer_id = lpt.id
    LEFT JOIN lent_puzzle lp ON lpt.lent_puzzle_id = lp.id
    LEFT JOIN puzzle ON COALESCE(lpt.puzzle_id, lp.puzzle_id) = puzzle.id
    LEFT JOIN manufacturer ON puzzle.manufacturer_id = manufacturer.id
    LEFT JOIN player from_player ON lpt.from_player_id = from_player.id
    LEFT JOIN player to_player ON lpt.to_player_id = to_player.id
    LEFT JOIN player owner_player ON COALESCE(lpt.owner_player_id, lp.owner_player_id) = owner_player.id
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
        NULL::boolean AS target_player_is_private,
        NULL::uuid AS puzzle_id,
        NULL::varchar AS puzzle_name,
        NULL::varchar AS manufacturer_name,
        NULL::int AS pieces_count,
        NULL::int AS time,
        NULL::boolean AS first_attempt,
        NULL::boolean AS unboxed,
        NULL::varchar AS puzzle_image,
        NULL::numeric AS puzzle_image_ratio,
        NULL::varchar AS team_id,
        NULL::varchar AS team_name,
        NULL::uuid AS guest_link_request_id,
        NULL::varchar AS guest_link_guest_name,
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
        NULL::numeric AS lending_puzzle_image_ratio,
        NULL::varchar AS lending_manufacturer_name,
        NULL::int AS lending_pieces_count,
        -- Puzzle change request fields
        pcr.id AS change_request_id,
        puzzle.id AS change_request_puzzle_id,
        puzzle.name AS change_request_puzzle_name,
        CASE WHEN puzzle.hide_image_until IS NOT NULL AND puzzle.hide_image_until > :now::timestamp THEN NULL ELSE puzzle.image END AS change_request_puzzle_image,
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
        NULL::varchar AS conversation_puzzle_image,
        -- Official results fields (NULL)
        NULL::uuid AS round_id,
        NULL::varchar AS round_name,
        NULL::varchar AS round_slug,
        NULL::varchar AS round_competition_name,
        NULL::varchar AS round_competition_slug,
        NULL::varchar AS round_series_slug,
        NULL::uuid AS target_time_id
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
        NULL::boolean AS target_player_is_private,
        NULL::uuid AS puzzle_id,
        NULL::varchar AS puzzle_name,
        NULL::varchar AS manufacturer_name,
        NULL::int AS pieces_count,
        NULL::int AS time,
        NULL::boolean AS first_attempt,
        NULL::boolean AS unboxed,
        NULL::varchar AS puzzle_image,
        NULL::numeric AS puzzle_image_ratio,
        NULL::varchar AS team_id,
        NULL::varchar AS team_name,
        NULL::uuid AS guest_link_request_id,
        NULL::varchar AS guest_link_guest_name,
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
        NULL::numeric AS lending_puzzle_image_ratio,
        NULL::varchar AS lending_manufacturer_name,
        NULL::int AS lending_pieces_count,
        -- Puzzle merge request fields
        NULL::uuid AS change_request_id,
        NULL::uuid AS change_request_puzzle_id,
        NULL::varchar AS change_request_puzzle_name,
        NULL::varchar AS change_request_puzzle_image,
        NULL::varchar AS change_request_rejection_reason,
        pmr.id AS merge_request_id,
        -- The reported puzzle, or - once it was merged away - the puzzle it became (an approved merge's survivor)
        merge_puzzle.id AS merge_request_puzzle_id,
        CASE WHEN pmr.source_puzzle_id IS NOT NULL THEN merge_puzzle.name ELSE COALESCE(pmr.source_puzzle_name, merge_puzzle.name) END AS merge_request_puzzle_name,
        CASE WHEN merge_puzzle.hide_image_until IS NOT NULL AND merge_puzzle.hide_image_until > :now::timestamp THEN NULL ELSE merge_puzzle.image END AS merge_request_puzzle_image,
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
        NULL::varchar AS conversation_puzzle_image,
        -- Official results fields (NULL)
        NULL::uuid AS round_id,
        NULL::varchar AS round_name,
        NULL::varchar AS round_slug,
        NULL::varchar AS round_competition_name,
        NULL::varchar AS round_competition_slug,
        NULL::varchar AS round_series_slug,
        NULL::uuid AS target_time_id
    FROM notification
    INNER JOIN puzzle_merge_request pmr ON notification.target_merge_request_id = pmr.id
    -- LEFT: an approved merge often deletes the reported puzzle (source_puzzle_id is set NULL) - the reporter is told
    -- all the same, by the name the request kept, with a link to the survivor (docs/features/navigation INB-02 review
    -- F1: the badge counts the row)
    LEFT JOIN puzzle merge_puzzle ON merge_puzzle.id = COALESCE(pmr.source_puzzle_id, pmr.survivor_puzzle_id)
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
        NULL::boolean AS target_player_is_private,
        NULL::uuid AS puzzle_id,
        NULL::varchar AS puzzle_name,
        NULL::varchar AS manufacturer_name,
        NULL::int AS pieces_count,
        NULL::int AS time,
        NULL::boolean AS first_attempt,
        NULL::boolean AS unboxed,
        NULL::varchar AS puzzle_image,
        NULL::numeric AS puzzle_image_ratio,
        NULL::varchar AS team_id,
        NULL::varchar AS team_name,
        NULL::uuid AS guest_link_request_id,
        NULL::varchar AS guest_link_guest_name,
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
        NULL::numeric AS lending_puzzle_image_ratio,
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
        CASE WHEN puzzle.hide_image_until IS NOT NULL AND puzzle.hide_image_until > :now::timestamp THEN NULL ELSE puzzle.image END AS rating_puzzle_image,
        CASE
            WHEN ssi.seller_id = notification.player_id THEN COALESCE(buyer.name, buyer.code, ssi.buyer_name)
            ELSE COALESCE(seller.name, seller.code, ssi.seller_name)
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
        NULL::varchar AS conversation_puzzle_image,
        -- Official results fields (NULL)
        NULL::uuid AS round_id,
        NULL::varchar AS round_name,
        NULL::varchar AS round_slug,
        NULL::varchar AS round_competition_name,
        NULL::varchar AS round_competition_slug,
        NULL::varchar AS round_series_slug,
        NULL::uuid AS target_time_id
    FROM notification
    INNER JOIN sold_swapped_item ssi ON notification.target_sold_swapped_item_id = ssi.id
    INNER JOIN puzzle ON ssi.puzzle_id = puzzle.id
    -- LEFT: a deleted seller is anonymised (seller_id NULL, seller_name kept) - the buyer is still asked to rate
    LEFT JOIN player seller ON ssi.seller_id = seller.id
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
        NULL::boolean AS target_player_is_private,
        NULL::uuid AS puzzle_id,
        NULL::varchar AS puzzle_name,
        NULL::varchar AS manufacturer_name,
        NULL::int AS pieces_count,
        NULL::int AS time,
        NULL::boolean AS first_attempt,
        NULL::boolean AS unboxed,
        NULL::varchar AS puzzle_image,
        NULL::numeric AS puzzle_image_ratio,
        NULL::varchar AS team_id,
        NULL::varchar AS team_name,
        NULL::uuid AS guest_link_request_id,
        NULL::varchar AS guest_link_guest_name,
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
        NULL::numeric AS lending_puzzle_image_ratio,
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
        CASE WHEN conv_puzzle.hide_image_until IS NOT NULL AND conv_puzzle.hide_image_until > :now::timestamp THEN NULL ELSE conv_puzzle.image END AS conversation_puzzle_image,
        -- Official results fields (NULL)
        NULL::uuid AS round_id,
        NULL::varchar AS round_name,
        NULL::varchar AS round_slug,
        NULL::varchar AS round_competition_name,
        NULL::varchar AS round_competition_slug,
        NULL::varchar AS round_series_slug,
        NULL::uuid AS target_time_id
    FROM notification
    INNER JOIN conversation conv ON notification.target_conversation_id = conv.id
    INNER JOIN player initiator ON conv.initiator_id = initiator.id
    LEFT JOIN puzzle conv_puzzle ON conv.puzzle_id = conv_puzzle.id
    WHERE notification.player_id = :playerId
        AND notification.target_conversation_id IS NOT NULL
        {$initiatorNotHidden}

    UNION ALL

    -- Community moderator role notifications (no target entity)
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
        NULL::boolean AS target_player_is_private,
        NULL::uuid AS puzzle_id,
        NULL::varchar AS puzzle_name,
        NULL::varchar AS manufacturer_name,
        NULL::int AS pieces_count,
        NULL::int AS time,
        NULL::boolean AS first_attempt,
        NULL::boolean AS unboxed,
        NULL::varchar AS puzzle_image,
        NULL::numeric AS puzzle_image_ratio,
        NULL::varchar AS team_id,
        NULL::varchar AS team_name,
        NULL::uuid AS guest_link_request_id,
        NULL::varchar AS guest_link_guest_name,
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
        NULL::numeric AS lending_puzzle_image_ratio,
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
        -- Conversation request fields (NULL)
        NULL::uuid AS conversation_id,
        NULL::uuid AS conversation_initiator_id,
        NULL::varchar AS conversation_initiator_name,
        NULL::varchar AS conversation_initiator_avatar,
        NULL::boolean AS conversation_is_marketplace,
        NULL::varchar AS conversation_puzzle_name,
        NULL::varchar AS conversation_puzzle_image,
        -- Official results fields (NULL)
        NULL::uuid AS round_id,
        NULL::varchar AS round_name,
        NULL::varchar AS round_slug,
        NULL::varchar AS round_competition_name,
        NULL::varchar AS round_competition_slug,
        NULL::varchar AS round_series_slug,
        NULL::uuid AS target_time_id
    FROM notification
    WHERE notification.player_id = :playerId
        AND notification.type IN ('ModeratorRoleGranted', 'ModeratorRoleRevoked')

    UNION ALL

    -- Official results of a round the player has a result in were published: the target is the round
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
        NULL::boolean AS target_player_is_private,
        NULL::uuid AS puzzle_id,
        NULL::varchar AS puzzle_name,
        NULL::varchar AS manufacturer_name,
        NULL::int AS pieces_count,
        NULL::int AS time,
        NULL::boolean AS first_attempt,
        NULL::boolean AS unboxed,
        NULL::varchar AS puzzle_image,
        NULL::numeric AS puzzle_image_ratio,
        NULL::varchar AS team_id,
        NULL::varchar AS team_name,
        NULL::uuid AS guest_link_request_id,
        NULL::varchar AS guest_link_guest_name,
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
        NULL::numeric AS lending_puzzle_image_ratio,
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
        -- Conversation request fields (NULL)
        NULL::uuid AS conversation_id,
        NULL::uuid AS conversation_initiator_id,
        NULL::varchar AS conversation_initiator_name,
        NULL::varchar AS conversation_initiator_avatar,
        NULL::boolean AS conversation_is_marketplace,
        NULL::varchar AS conversation_puzzle_name,
        NULL::varchar AS conversation_puzzle_image,
        -- Official results fields
        competition_round.id AS round_id,
        competition_round.name AS round_name,
        competition_round.slug AS round_slug,
        competition.name AS round_competition_name,
        competition.slug AS round_competition_slug,
        competition_series.slug AS round_series_slug,
        NULL::uuid AS target_time_id
    FROM notification
    INNER JOIN competition_round ON competition_round.id = notification.target_competition_round_id
    INNER JOIN competition ON competition.id = competition_round.competition_id
    LEFT JOIN competition_series ON competition_series.id = competition.series_id
    WHERE notification.player_id = :playerId
        AND notification.target_competition_round_id IS NOT NULL

    UNION ALL

    -- A pair/team was named or renamed: the target is the team, the player shown is whoever did it
    SELECT
        notification.notified_at,
        notification.read_at,
        notification.type AS notification_type,
        -- Puzzle solving fields (NULL)
        actor.id AS target_player_id,
        actor.name AS target_player_name,
        actor.code AS target_player_code,
        actor.country AS target_player_country,
        actor.avatar AS target_player_avatar,
        false AS target_player_is_private,
        NULL::uuid AS puzzle_id,
        NULL::varchar AS puzzle_name,
        NULL::varchar AS manufacturer_name,
        NULL::int AS pieces_count,
        NULL::int AS time,
        NULL::boolean AS first_attempt,
        NULL::boolean AS unboxed,
        NULL::varchar AS puzzle_image,
        NULL::numeric AS puzzle_image_ratio,
        notification.target_puzzling_team_id::varchar AS team_id,
        puzzling_team.name AS team_name,
        NULL::uuid AS guest_link_request_id,
        NULL::varchar AS guest_link_guest_name,
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
        NULL::numeric AS lending_puzzle_image_ratio,
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
        -- Conversation request fields (NULL)
        NULL::uuid AS conversation_id,
        NULL::uuid AS conversation_initiator_id,
        NULL::varchar AS conversation_initiator_name,
        NULL::varchar AS conversation_initiator_avatar,
        NULL::boolean AS conversation_is_marketplace,
        NULL::varchar AS conversation_puzzle_name,
        NULL::varchar AS conversation_puzzle_image,
        -- Official results fields (NULL)
        NULL::uuid AS round_id,
        NULL::varchar AS round_name,
        NULL::varchar AS round_slug,
        NULL::varchar AS round_competition_name,
        NULL::varchar AS round_competition_slug,
        NULL::varchar AS round_series_slug,
        NULL::uuid AS target_time_id
    FROM notification
    INNER JOIN puzzling_team ON puzzling_team.id = notification.target_puzzling_team_id
    INNER JOIN player actor ON actor.id = notification.actor_player_id
    WHERE notification.player_id = :playerId
        AND notification.target_puzzling_team_id IS NOT NULL
        {$actorNotHidden}

    UNION ALL

    -- "This guest of mine is you": asked (shown to whoever was asked) and agreed to (shown to whoever asked)
    SELECT
        notification.notified_at,
        notification.read_at,
        notification.type AS notification_type,
        -- Puzzle solving fields (NULL)
        actor.id AS target_player_id,
        actor.name AS target_player_name,
        actor.code AS target_player_code,
        actor.country AS target_player_country,
        actor.avatar AS target_player_avatar,
        false AS target_player_is_private,
        NULL::uuid AS puzzle_id,
        NULL::varchar AS puzzle_name,
        NULL::varchar AS manufacturer_name,
        NULL::int AS pieces_count,
        NULL::int AS time,
        NULL::boolean AS first_attempt,
        NULL::boolean AS unboxed,
        NULL::varchar AS puzzle_image,
        NULL::numeric AS puzzle_image_ratio,
        NULL::varchar AS team_id,
        NULL::varchar AS team_name,
        guest_link_request.id AS guest_link_request_id,
        guest_link_request.guest_name AS guest_link_guest_name,
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
        NULL::numeric AS lending_puzzle_image_ratio,
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
        -- Conversation request fields (NULL)
        NULL::uuid AS conversation_id,
        NULL::uuid AS conversation_initiator_id,
        NULL::varchar AS conversation_initiator_name,
        NULL::varchar AS conversation_initiator_avatar,
        NULL::boolean AS conversation_is_marketplace,
        NULL::varchar AS conversation_puzzle_name,
        NULL::varchar AS conversation_puzzle_image,
        -- Official results fields (NULL)
        NULL::uuid AS round_id,
        NULL::varchar AS round_name,
        NULL::varchar AS round_slug,
        NULL::varchar AS round_competition_name,
        NULL::varchar AS round_competition_slug,
        NULL::varchar AS round_series_slug,
        NULL::uuid AS target_time_id
    FROM notification
    INNER JOIN guest_link_request ON guest_link_request.id = notification.target_guest_link_request_id
    INNER JOIN player actor ON actor.id = notification.actor_player_id
    WHERE notification.player_id = :playerId
        AND notification.target_guest_link_request_id IS NOT NULL
        {$actorNotHidden}
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
                'now' => $this->clock->now()->format('Y-m-d H:i:s'),
            ])
            ->fetchAllAssociative();

        return array_map(static function (array $row): PlayerNotification {
            return PlayerNotification::fromDatabaseRow($row);
        }, $data);
    }

    /**
     * Favorites' times are not notifications any more (docs/features/navigation INB-03, README D4): nothing creates a
     * SubscribedPlayerAddedTime row and the rows that exist are neither listed nor counted (GetInboxBadge::NOT_FOR_YOU)
     * until the D4 cleanup deletes them. Their content is Home › Favorites. The type is inlined as a literal from the
     * enum, never bound. NewConversationRequest rows stay listed here - the badge counts them under Messages.
     */
    private static function sqlListed(): string
    {
        return sprintf("AND notification.type <> '%s'", NotificationType::SubscribedPlayerAddedTime->value);
    }

    /**
     * The same rule as the hiding branches of forPlayer() (actor, solving time, conversation), for the queries that
     * read the notification table alone - shared with the Inbox badge (GetInboxBadge), which must not count what the
     * list leaves out (HiddenPlayers::sqlNotificationNotHiddenFor()).
     */
    private function unreadNotHidden(): string
    {
        return $this->hiddenPlayers->sqlNotificationNotHidden();
    }
}
