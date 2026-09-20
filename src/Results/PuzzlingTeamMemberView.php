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

    public function isGuest(): bool
    {
        return $this->playerId === null;
    }
}
