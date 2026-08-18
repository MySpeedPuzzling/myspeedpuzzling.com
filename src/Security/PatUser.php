<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Security;

use SpeedPuzzling\Web\Entity\Player;

final readonly class PatUser implements ApiUser
{
    public const string ROLE = 'ROLE_PAT';

    public function __construct(
        private Player $player,
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
