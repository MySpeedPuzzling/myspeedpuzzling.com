<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Security;

use Doctrine\ORM\EntityManagerInterface;
use SpeedPuzzling\Web\Entity\Player;
use Symfony\Component\Security\Core\Exception\UserNotFoundException;
use Symfony\Component\Security\Core\User\UserInterface;
use Symfony\Component\Security\Core\User\UserProviderInterface;

/**
 * @implements UserProviderInterface<OAuth2User>
 */
final readonly class OAuth2UserProvider implements UserProviderInterface
{
    public function __construct(
        private EntityManagerInterface $entityManager,
    ) {
    }

    public function refreshUser(UserInterface $user): UserInterface
    {
        return $this->loadUserByIdentifier($user->getUserIdentifier());
    }

    public function supportsClass(string $class): bool
    {
        return $class === OAuth2User::class;
    }

    public function loadUserByIdentifier(string $identifier): UserInterface
    {
        $player = $this->entityManager->find(Player::class, $identifier);

        if ($player === null) {
            throw new UserNotFoundException(sprintf('Player with ID "%s" not found.', $identifier));
        }

        return new OAuth2User($player);
    }
}
