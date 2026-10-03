<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Results;

use SpeedPuzzling\Web\Value\CountryCode;

readonly final class PuzzlingTeamMemberView
{
    public function __construct(
        // Null for a guest without an account
        public null|string $playerId,
        public null|string $playerName,
        public null|string $playerCode,
        public null|CountryCode $playerCountry,
        public null|string $guestName,
        // Hidden from this viewer (PrivateProfileAccess) - shown as "Hidden Puzzler"
        public bool $isPrivate,
    ) {
    }

    /**
     * Members aggregated by a query as a JSON list of objects with the keys 'player_id', 'guest_name', 'player_code',
     * 'player_name', 'player_country' and 'is_private' - masking already applied in SQL.
     *
     * @return list<self>
     */
    public static function listFromJson(null|string $json): array
    {
        if ($json === null) {
            return [];
        }

        /** @var list<array{player_id: null|string, guest_name: null|string, player_code: null|string, player_name: null|string, player_country: null|string, is_private: bool}> $members */
        $members = json_decode($json, true, flags: JSON_THROW_ON_ERROR);

        return array_map(static fn(array $member): self => new self(
            playerId: $member['player_id'],
            playerName: $member['player_name'],
            playerCode: $member['player_code'] !== null ? strtoupper($member['player_code']) : null,
            playerCountry: CountryCode::fromCode($member['player_country']),
            guestName: $member['guest_name'],
            isPrivate: $member['is_private'],
        ), $members);
    }

    public function isGuest(): bool
    {
        return $this->playerId === null;
    }
}
