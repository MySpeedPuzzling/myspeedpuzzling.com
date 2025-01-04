<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Results;

use DateTimeImmutable;
use Nette\Utils\Json;
use Nette\Utils\JsonException;
use SpeedPuzzling\Web\Value\CountryCode;

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
        public bool $wjpcModalDisplayed,
        public null|string $stripeCustomerId,
        public null|string $locale,
        public null|DateTimeImmutable $membershipEndsAt,
        public bool $activeMembership,
        public null|CountryCode $countryCode = null,
    ) {
    }

    /**
     * @param array{
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
     *     wjpc_modal_displayed: bool,
     *     locale: null|string,
     *     membership_ends_at: null|string,
     * } $row
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
            wjpcModalDisplayed: $row['wjpc_modal_displayed'],
            stripeCustomerId: $row['stripe_customer_id'],
            locale: $row['locale'],
            membershipEndsAt: $membershipEndsAt,
            activeMembership: $hasMembership,
            countryCode: $countryCode,
        );
    }
}
