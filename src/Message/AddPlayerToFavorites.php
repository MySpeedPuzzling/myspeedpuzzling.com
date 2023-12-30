<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Message;

readonly final class AddPlayerToFavorites
{
    public function __construct(
        public string $currentUserId,
        public string $favoritePlayerId,
    ) {
    }
}
