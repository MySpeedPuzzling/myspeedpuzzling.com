<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Security;

use SpeedPuzzling\Web\Entity\Player;
use Symfony\Component\Security\Core\User\UserInterface;

final readonly class OAuth2User implements UserInterface
{
    public function __construct(
        public Player $player,
    ) {
    }

    public function getRoles(): array
    {
        return ['ROLE_OAUTH2_USER'];
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
