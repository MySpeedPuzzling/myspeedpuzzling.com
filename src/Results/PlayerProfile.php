<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Results;

use DateTimeImmutable;
use Nette\Utils\Json;
use Nette\Utils\JsonException;
use SpeedPuzzling\Web\Value\CollectionVisibility;
use SpeedPuzzling\Web\Value\CountryCode;

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
        public bool $isAdmin = false,
        public bool $isPrivate = false,
        public null|CountryCode $countryCode = null,
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
            isAdmin: $row['is_admin'],
            isPrivate: $row['is_private'],
            countryCode: $countryCode,
        );
    }
}
