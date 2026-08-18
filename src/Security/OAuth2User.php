<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Security;

use SpeedPuzzling\Web\Entity\Player;

final readonly class OAuth2User implements ApiUser
{
    /**
     * Marks a token that carries a real user (authorization-code flow). The
     * bundle merges these roles into the scope roles, so access_control can
     * tell "OAuth2 on behalf of a player" apart from a client_credentials token,
     * which authenticates as ClientCredentialsUser with no roles at all.
     */
    public const string ROLE = 'ROLE_OAUTH2_USER';

    public function __construct(
        public Player $player,
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
