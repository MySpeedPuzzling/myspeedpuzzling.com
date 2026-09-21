<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Results;

use DateTimeImmutable;
use Nette\Utils\Json;
use Nette\Utils\JsonException;
use SpeedPuzzling\Web\Value\CollectionVisibility;
use SpeedPuzzling\Web\Value\CountryCode;
use SpeedPuzzling\Web\Value\EmailNotificationFrequency;
use SpeedPuzzling\Web\Value\SellSwapListSettings;

/**
 * @phpstan-type PlayerProfileRow array{
 *     player_id: string,
 *     user_id: null|string,
 *     player_name: null|string,
 *     email: null|string,
 *     country: null|string,
 *     city: null|string,
 *     code: string,
 *     favorite_players: string,
 *     hidden_player_ids?: null|string,
 *     avatar: null|string,
 *     bio: null|string,
 *     facebook: null|string,
 *     instagram: null|string,
 *     twitch: null|string,
 *     stripe_customer_id: null|string,
 *     locale: null|string,
 *     has_active_stripe_subscription: bool,
 *     membership_ends_at: null|string,
 *     is_admin: bool,
 *     is_private: bool,
 *     is_private_profile?: bool,
 *     revealed_private_player_ids?: null|string,
 *     puzzle_collection_visibility: string,
 *     unsolved_puzzles_visibility: string,
 *     wish_list_visibility: string,
 *     lend_borrow_list_visibility: string,
 *     solved_puzzles_visibility: string,
 *     sell_swap_list_settings: null|string,
 *     allow_direct_messages: bool,
 *     email_notifications_enabled: bool,
 *     email_notification_frequency: string,
 *     newsletter_enabled: bool,
 *     rating_count: int|string,
 *     average_rating: null|string,
 *     streak_opted_out: bool,
 *     ranking_opted_out: bool,
 *     time_predictions_opted_out: bool,
 *     fair_use_policy_accepted_at: null|string,
 *     referral_program_joined_at: null|string,
 *     referral_program_suspended: bool,
 *     moderator_since: null|string,
 *     registered_at?: null|string,
 *     has_membership_row?: bool,
 *     free_trial_ends_at?: null|string,
 *     modal_impressions?: null|string,
 *  }
 */
readonly final class PlayerProfile
{
    public function __construct(
        public string $playerId,
        public null|string $userId,
        public null|string $playerName,
        public null|string $email,
        public null|string $country,
        public null|string $city,
        public string $code,
        /** @var array<string> */
        public array $favoritePlayers,
        public null|string $avatar,
        public null|string $bio,
        public null|string $facebook,
        public null|string $instagram,
        public null|string $twitch,
        public null|string $stripeCustomerId,
        public null|string $locale,
        public null|DateTimeImmutable $membershipEndsAt,
        public bool $activeMembership,
        public CollectionVisibility $puzzleCollectionVisibility,
        public CollectionVisibility $unsolvedPuzzlesVisibility,
        public CollectionVisibility $wishListVisibility,
        public CollectionVisibility $lendBorrowListVisibility,
        public CollectionVisibility $solvedPuzzlesVisibility,
        public null|SellSwapListSettings $sellSwapListSettings = null,
        public bool $isAdmin = false,
        public bool $isPrivate = false,
        public null|CountryCode $countryCode = null,
        public bool $allowDirectMessages = true,
        public bool $emailNotificationsEnabled = true,
        public EmailNotificationFrequency $emailNotificationFrequency = EmailNotificationFrequency::TwentyFourHours,
        public bool $newsletterEnabled = true,
        public int $ratingCount = 0,
        public null|float $averageRating = null,
        public bool $streakOptedOut = false,
        public bool $rankingOptedOut = false,
        public bool $timePredictionsOptedOut = false,
        public bool $fairUsePolicyAccepted = false,
        public null|DateTimeImmutable $referralProgramJoinedAt = null,
        public bool $referralProgramSuspended = false,
        public bool $isModerator = false,
        /**
         * Players this one must not be shown (docs/features/player-blocklist.md), admin-imposed
         * blocks included - for HiddenPlayers only, never for display. Filled for the signed-in
         * player's own profile (GetPlayerProfile::byUserId()) and empty otherwise.
         *
         * @var list<string>
         */
        public array $hiddenPlayerIds = [],
        /**
         * The player's own setting, whoever is looking. `$isPrivate` answers "hidden from this
         * viewer" and is what nearly everything wants; this is for reporting the setting itself.
         */
        public bool $isPrivateProfile = false,
        /**
         * The viewer's own profile only: private players whose allow list names this player
         * (docs/features/private-profile-allow-list.md). Read through PrivateProfileAccess, never directly.
         *
         * @var list<string>
         */
        public array $revealedPrivatePlayerIds = [],
        /**
         * The four below are filled for the signed-in player's own profile only (GetPlayerProfile::byUserId()).
         */
        public null|DateTimeImmutable $registeredAt = null,
        /**
         * No membership row at all - never subscribed, never claimed a voucher, never was granted one, never
         * had a trial. The one eligibility rule of the free trial (docs/features/free-trial/README.md).
         */
        public bool $freeTrialAvailable = false,
        /**
         * Set while the free trial is what the membership runs on - not once the player subscribed.
         */
        public null|DateTimeImmutable $freeTrialEndsAt = null,
        /**
         * Announcement modals this player was already shown, by AnnouncementModal value
         * (docs/features/announcement-modals.md). Read through ResolveAnnouncementModal.
         *
         * @var array<string, DateTimeImmutable>
         */
        public array $modalImpressions = [],
    ) {
    }

    /**
     * @param PlayerProfileRow $row
     */
    public static function fromDatabaseRow(array $row, DateTimeImmutable $now): self
    {
        try {
            /** @var array<string> $favoritePlayers */
            $favoritePlayers = Json::decode($row['favorite_players'], true);
        } catch (JsonException) {
            $favoritePlayers = [];
        }

        $revealedPrivatePlayerIds = [];

        if (is_string($row['revealed_private_player_ids'] ?? null)) {
            $decodedRevealed = Json::decode($row['revealed_private_player_ids'], true);

            if (is_array($decodedRevealed)) {
                $revealedPrivatePlayerIds = array_values(array_filter($decodedRevealed, is_string(...)));
            }
        }

        $hiddenPlayerIds = [];

        try {
            $decoded = Json::decode($row['hidden_player_ids'] ?? '[]', true);

            if (is_array($decoded)) {
                $hiddenPlayerIds = array_values(array_filter($decoded, is_string(...)));
            }
        } catch (JsonException) {
        }

        $modalImpressions = [];

        try {
            $decodedImpressions = Json::decode($row['modal_impressions'] ?? '{}', true);

            if (is_array($decodedImpressions)) {
                foreach ($decodedImpressions as $modal => $displayedAt) {
                    if (is_string($modal) && is_string($displayedAt)) {
                        $modalImpressions[$modal] = new DateTimeImmutable($displayedAt);
                    }
                }
            }
        } catch (JsonException) {
        }

        $countryCode = CountryCode::fromCode($row['country']);

        $membershipEndsAt = null;
        $hasMembership = false;

        if ($row['has_active_stripe_subscription']) {
            $hasMembership = true;
        }

        if ($row['membership_ends_at'] !== null) {
            $membershipEndsAt = new DateTimeImmutable($row['membership_ends_at']);

            if ($membershipEndsAt > $now) {
                $hasMembership = true;
            }
        }

        // Once the player subscribes, the rest of the trial becomes a Stripe trial of that subscription
        $freeTrialEndsAt = null;

        if (isset($row['free_trial_ends_at']) && $row['has_active_stripe_subscription'] === false) {
            $trialEndsAt = new DateTimeImmutable($row['free_trial_ends_at']);

            if ($trialEndsAt > $now) {
                $freeTrialEndsAt = $trialEndsAt;
            }
        }

        $sellSwapListSettings = null;
        if ($row['sell_swap_list_settings'] !== null) {
            try {
                /** @var array{description?: null|string, currency?: null|string, custom_currency?: null|string, shipping_info?: null|string, contact_info?: null|string, shipping_countries?: string[], shipping_cost?: null|string} $settingsData */
                $settingsData = Json::decode($row['sell_swap_list_settings'], true);
                $sellSwapListSettings = new SellSwapListSettings(
                    description: $settingsData['description'] ?? null,
                    currency: $settingsData['currency'] ?? null,
                    customCurrency: $settingsData['custom_currency'] ?? null,
                    shippingInfo: $settingsData['shipping_info'] ?? null,
                    contactInfo: $settingsData['contact_info'] ?? null,
                    shippingCountries: $settingsData['shipping_countries'] ?? [],
                    shippingCost: $settingsData['shipping_cost'] ?? null,
                );
            } catch (JsonException) {
                // Invalid JSON, keep null
            }
        }

        return new self(
            playerId: $row['player_id'],
            userId: $row['user_id'],
            playerName: $row['player_name'],
            email: $row['email'],
            country: $row['country'],
            city: $row['city'],
            code: $row['code'],
            favoritePlayers: $favoritePlayers,
            avatar: $row['avatar'],
            bio: $row['bio'],
            facebook: $row['facebook'],
            instagram: $row['instagram'],
            twitch: $row['twitch'],
            stripeCustomerId: $row['stripe_customer_id'],
            locale: $row['locale'],
            membershipEndsAt: $membershipEndsAt,
            activeMembership: $hasMembership,
            puzzleCollectionVisibility: CollectionVisibility::from($row['puzzle_collection_visibility']),
            unsolvedPuzzlesVisibility: CollectionVisibility::from($row['unsolved_puzzles_visibility']),
            wishListVisibility: CollectionVisibility::from($row['wish_list_visibility']),
            lendBorrowListVisibility: CollectionVisibility::from($row['lend_borrow_list_visibility']),
            solvedPuzzlesVisibility: CollectionVisibility::from($row['solved_puzzles_visibility']),
            sellSwapListSettings: $sellSwapListSettings,
            isAdmin: $row['is_admin'],
            isPrivate: $row['is_private'],
            countryCode: $countryCode,
            allowDirectMessages: (bool) $row['allow_direct_messages'],
            emailNotificationsEnabled: (bool) $row['email_notifications_enabled'],
            emailNotificationFrequency: EmailNotificationFrequency::from($row['email_notification_frequency']),
            newsletterEnabled: (bool) $row['newsletter_enabled'],
            ratingCount: (int) $row['rating_count'],
            averageRating: $row['average_rating'] !== null ? (float) $row['average_rating'] : null,
            streakOptedOut: (bool) $row['streak_opted_out'],
            rankingOptedOut: (bool) $row['ranking_opted_out'],
            timePredictionsOptedOut: (bool) $row['time_predictions_opted_out'],
            fairUsePolicyAccepted: $row['fair_use_policy_accepted_at'] !== null,
            referralProgramJoinedAt: $row['referral_program_joined_at'] !== null
                ? new DateTimeImmutable($row['referral_program_joined_at'])
                : null,
            referralProgramSuspended: (bool) $row['referral_program_suspended'],
            isModerator: $row['moderator_since'] !== null,
            hiddenPlayerIds: $hiddenPlayerIds,
            isPrivateProfile: $row['is_private_profile'] ?? $row['is_private'],
            revealedPrivatePlayerIds: $revealedPrivatePlayerIds,
            registeredAt: isset($row['registered_at']) ? new DateTimeImmutable($row['registered_at']) : null,
            freeTrialAvailable: ($row['has_membership_row'] ?? true) === false,
            freeTrialEndsAt: $freeTrialEndsAt,
            modalImpressions: $modalImpressions,
        );
    }

    public function isInReferralProgram(): bool
    {
        return $this->referralProgramJoinedAt !== null && !$this->referralProgramSuspended;
    }
}
