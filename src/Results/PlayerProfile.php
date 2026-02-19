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
 *     avatar: null|string,
 *     bio: null|string,
 *     facebook: null|string,
 *     instagram: null|string,
 *     stripe_customer_id: null|string,
 *     modal_displayed: bool,
 *     locale: null|string,
 *     membership_ends_at: null|string,
 *     is_admin: bool,
 *     is_private: bool,
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
        public bool $modalDisplayed,
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

        $countryCode = CountryCode::fromCode($row['country']);

        $membershipEndsAt = null;
        $hasMembership = false;

        if ($row['membership_ends_at'] !== null) {
            $membershipEndsAt = new DateTimeImmutable($row['membership_ends_at']);

            if ($membershipEndsAt > $now) {
                $hasMembership = true;
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
            modalDisplayed: $row['modal_displayed'],
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
        );
    }
}
