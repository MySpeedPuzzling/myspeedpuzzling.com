<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Security;

use SpeedPuzzling\Web\Entity\Player;

final readonly class PatUser implements ApiUser
{
    public const string ROLE = 'ROLE_PAT';

    public function __construct(
        private Player $player,
        // Which of the player's tokens made the request - the API usage statistics count per token
        public string $personalAccessTokenId,
    ) {
    }

    public function getPlayer(): Player
    {
        return $this->player;
    }

    public function getRoles(): array
    {
        return [self::ROLE];
    }

    public function eraseCredentials(): void
    {
        // No credentials stored
    }

    public function getUserIdentifier(): string
    {
        return $this->player->id->toString();
    }
}
