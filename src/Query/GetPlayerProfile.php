<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Query;

use Doctrine\DBAL\Connection;
use Psr\Clock\ClockInterface;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Exceptions\PlayerNotFound;
use SpeedPuzzling\Web\Results\PlayerProfile;
use SpeedPuzzling\Web\Services\HiddenPlayers;
use SpeedPuzzling\Web\Services\PrivateProfileAccess;

/**
 * @phpstan-import-type PlayerProfileRow from PlayerProfile
 */
readonly final class GetPlayerProfile
{
    public function __construct(
        private Connection $database,
        private ClockInterface $clock,
        private HiddenPlayers $hiddenPlayers,
        private PrivateProfileAccess $privateProfileAccess,
    ) {
    }

    /**
     * A player the viewer has blocked (or was blocked from seeing) does not exist for them: every
     * profile page, sub-page and API resource resolves its subject here, so they all answer the
     * same 404 a deleted player gets - see docs/features/player-blocklist.md.
     *
     * @throws PlayerNotFound
     */
    public function byId(string $playerId): PlayerProfile
    {
        if (Uuid::isValid($playerId) === false || $this->hiddenPlayers->isHidden($playerId)) {
            throw new PlayerNotFound();
        }

        $isPrivate = $this->privateProfileAccess->sqlIsPrivate('player');

        $query = <<<SQL
SELECT
    player.id AS player_id,
    name AS player_name,
    user_id,
    email,
    country,
    city,
    code,
    favorite_players,
    avatar,
    bio,
    facebook,
    instagram,
    twitch,
    stripe_customer_id,
    modal_displayed,
    locale,
    is_admin,
    {$isPrivate} AS is_private,
    player.is_private AS is_private_profile,
    puzzle_collection_visibility,
    unsolved_puzzles_visibility,
    wish_list_visibility,
    lend_borrow_list_visibility,
    solved_puzzles_visibility,
    sell_swap_list_settings,
    allow_direct_messages,
    email_notifications_enabled,
    email_notification_frequency,
    newsletter_enabled,
    rating_count,
    average_rating,
    streak_opted_out,
    ranking_opted_out,
    time_predictions_opted_out,
    fair_use_policy_accepted_at,
    referral_program_joined_at,
    referral_program_suspended,
    moderator_since,
    (membership.ends_at IS NULL AND membership.billing_period_ends_at IS NOT NULL) AS has_active_stripe_subscription,
    GREATEST(
        COALESCE(membership.ends_at, membership.billing_period_ends_at, '1970-01-01'::timestamp),
        COALESCE(membership.granted_until, '1970-01-01'::timestamp)
    ) AS membership_ends_at
FROM player
LEFT JOIN membership ON membership.player_id = player.id
WHERE player.id = :playerId
SQL;

        /**
         * @var false|PlayerProfileRow $row
         */
        $row = $this->database
            ->executeQuery($query, [
                'playerId' => $playerId,
            ])
            ->fetchAssociative();

        if (is_array($row) === false) {
            throw new PlayerNotFound();
        }

        return PlayerProfile::fromDatabaseRow($row, $this->clock->now());
    }

    /**
     * @throws PlayerNotFound
     */
    public function byUserId(string $userId): PlayerProfile
    {
        $revealedIds = PrivateProfileAccess::sqlRevealedIdsOf('player');

        $query = <<<SQL
SELECT
    player.id AS player_id,
    name AS player_name,
    user_id,
    email,
    country,
    city,
    code,
    favorite_players,
    avatar,
    bio,
    facebook,
    instagram,
    twitch,
    stripe_customer_id,
    modal_displayed,
    locale,
    is_admin,
    is_private,
    {$revealedIds} AS revealed_private_player_ids,
    puzzle_collection_visibility,
    unsolved_puzzles_visibility,
    wish_list_visibility,
    lend_borrow_list_visibility,
    solved_puzzles_visibility,
    sell_swap_list_settings,
    allow_direct_messages,
    email_notifications_enabled,
    email_notification_frequency,
    newsletter_enabled,
    rating_count,
    average_rating,
    streak_opted_out,
    ranking_opted_out,
    time_predictions_opted_out,
    fair_use_policy_accepted_at,
    referral_program_joined_at,
    referral_program_suspended,
    moderator_since,
    (SELECT json_agg(user_block.blocked_id) FROM user_block WHERE user_block.blocker_id = player.id) AS hidden_player_ids,
    (membership.ends_at IS NULL AND membership.billing_period_ends_at IS NOT NULL) AS has_active_stripe_subscription,
    GREATEST(
        COALESCE(membership.ends_at, membership.billing_period_ends_at, '1970-01-01'::timestamp),
        COALESCE(membership.granted_until, '1970-01-01'::timestamp)
    ) AS membership_ends_at
FROM player
LEFT JOIN membership ON membership.player_id = player.id
WHERE player.user_id = :userId
SQL;

        /**
         * @var false|PlayerProfileRow $row
         */
        $row = $this->database
            ->executeQuery($query, [
                'userId' => $userId,
            ])
            ->fetchAssociative();

        if (is_array($row) === false) {
            throw new PlayerNotFound();
        }

        return PlayerProfile::fromDatabaseRow($row, $this->clock->now());
    }
}
