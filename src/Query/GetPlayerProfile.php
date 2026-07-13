<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Query;

use Doctrine\DBAL\Connection;
use Psr\Clock\ClockInterface;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Exceptions\PlayerNotFound;
use SpeedPuzzling\Web\Results\ComparisonLineUp;
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
    player.user_id,
    user_account.email,
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
    result_emails_enabled,
    rating_count,
    average_rating,
    streak_opted_out,
    content_digest_frequency,
    experience_system_opted_out,
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
LEFT JOIN user_account ON user_account.user_id = player.user_id
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
        $comparisonLineUp = self::sqlComparisonLineUpOf('player');

        $query = <<<SQL
SELECT
    player.id AS player_id,
    name AS player_name,
    player.user_id,
    user_account.email,
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
    result_emails_enabled,
    rating_count,
    average_rating,
    streak_opted_out,
    content_digest_frequency,
    experience_system_opted_out,
    ranking_opted_out,
    time_predictions_opted_out,
    fair_use_policy_accepted_at,
    referral_program_joined_at,
    referral_program_suspended,
    moderator_since,
    (SELECT json_agg(user_block.blocked_id) FROM user_block WHERE user_block.blocker_id = player.id) AS hidden_player_ids,
    player.registered_at,
    (membership.id IS NOT NULL) AS has_membership_row,
    membership.trial_ends_at AS free_trial_ends_at,
    (SELECT json_object_agg(impression.modal, impression.displayed_at) FROM player_modal_impression impression WHERE impression.player_id = player.id) AS modal_impressions,
    player.leaderboard_chart_view,
    player.comparison_view,
    {$comparisonLineUp} AS comparison_line_up,
    (membership.ends_at IS NULL AND membership.billing_period_ends_at IS NOT NULL) AS has_active_stripe_subscription,
    GREATEST(
        COALESCE(membership.ends_at, membership.billing_period_ends_at, '1970-01-01'::timestamp),
        COALESCE(membership.granted_until, '1970-01-01'::timestamp)
    ) AS membership_ends_at
FROM player
LEFT JOIN user_account ON user_account.user_id = player.user_id
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

    /**
     * The viewer's comparison line-ups (docs/features/player-comparison.md) for their own profile row - the launcher,
     * the profile/team buttons and the comparison's kind switch then cost no query. Every row as a ref; the newest
     * subjects other than the viewer (`recent` = 1, 2, 3) also with what a mini avatar needs - masking happens in
     * PlayerProfile, against the hidden and revealed ids of this same row.
     */
    private static function sqlComparisonLineUpOf(string $viewerAlias): string
    {
        $recentLimit = ComparisonLineUp::RECENT_LIMIT;

        return <<<SQL
(
        SELECT json_agg(json_build_object(
            'id', line_up.id,
            'subject_player_id', line_up.subject_player_id,
            'subject_team_id', line_up.subject_team_id,
            'team_size', line_up.team_size,
            'added_at', line_up.added_at,
            'is_self', line_up.is_self,
            'recent', CASE WHEN line_up.is_self = false AND line_up.recent_rank <= {$recentLimit} THEN line_up.recent_rank END,
            'subject', CASE WHEN subject.id IS NOT NULL THEN json_build_object(
                'name', subject.name,
                'code', subject.code,
                'avatar', subject.avatar,
                'country', subject.country,
                'is_private', subject.is_private
            ) END
        ) ORDER BY line_up.added_at, line_up.id)
        FROM (
            SELECT
                comparison_subject.id,
                comparison_subject.subject_player_id,
                comparison_subject.subject_team_id,
                team.size AS team_size,
                comparison_subject.added_at,
                COALESCE(comparison_subject.subject_player_id = comparison_subject.player_id, false) AS is_self,
                ROW_NUMBER() OVER (
                    PARTITION BY comparison_subject.subject_player_id IS NOT DISTINCT FROM comparison_subject.player_id
                    ORDER BY comparison_subject.added_at DESC, comparison_subject.id DESC
                ) AS recent_rank
            FROM comparison_subject
            LEFT JOIN puzzling_team team ON team.id = comparison_subject.subject_team_id
            WHERE comparison_subject.player_id = {$viewerAlias}.id
        ) line_up
        LEFT JOIN player subject ON subject.id = line_up.subject_player_id
            AND line_up.is_self = false
            AND line_up.recent_rank <= {$recentLimit}
    )
SQL;
    }
}
