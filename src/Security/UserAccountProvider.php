<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Security;

use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use SpeedPuzzling\Web\Entity\UserAccount;
use SpeedPuzzling\Web\Repository\UserAccountRepository;
use Symfony\Component\Security\Core\Exception\UnsupportedUserException;
use Symfony\Component\Security\Core\Exception\UserNotFoundException;
use Symfony\Component\Security\Core\User\PasswordAuthenticatedUserInterface;
use Symfony\Component\Security\Core\User\PasswordUpgraderInterface;
use Symfony\Component\Security\Core\User\UserInterface;
use Symfony\Component\Security\Core\User\UserProviderInterface;

/**
 * Resolves session/remember-me/login-link identifiers to UserAccount rows.
 * The identifier is the user_id string (auth0|... or msp|...) - the same
 * identity string the whole write path keys on. The login form authenticates
 * by email instead; that lookup lives in LoginFormAuthenticator's UserBadge
 * loader (badge identifier != user identifier, intentionally - README §UserAccount).
 *
 * @implements UserProviderInterface<UserAccount>
 */
final readonly class UserAccountProvider implements UserProviderInterface, PasswordUpgraderInterface
{
    public function __construct(
        private UserAccountRepository $userAccountRepository,
        private EntityManagerInterface $entityManager,
        private LoggerInterface $logger,
    ) {
    }

    public function loadUserByIdentifier(string $identifier): UserAccount
    {
        return $this->userAccountRepository->findByUserId($identifier)
            ?? throw new UserNotFoundException(sprintf('User account "%s" not found.', $identifier));
    }

    public function refreshUser(UserInterface $user): UserAccount
    {
        if (!$user instanceof UserAccount) {
            throw new UnsupportedUserException(sprintf('Instances of "%s" are not supported.', $user::class));
        }

        return $this->loadUserByIdentifier($user->getUserIdentifier());
    }

    public function supportsClass(string $class): bool
    {
        return $class === UserAccount::class || is_subclass_of($class, UserAccount::class);
    }

    public function upgradePassword(PasswordAuthenticatedUserInterface $user, string $newHashedPassword): void
    {
        if (!$user instanceof UserAccount) {
            return;
        }

        // Phase 5 exit-metric counter: imported bcrypt hash replaced by argon2id
        // on first successful login (trickle adoptions arrive here with password
        // still null, so they are counted separately as trickle_used)
        if ($user->password !== null && str_starts_with($user->password, '$2')) {
            $this->logger->info('Imported bcrypt hash re-hashed to argon2id.', [
                'user_id' => $user->getUserIdentifier(),
                'bcrypt_rehashed' => true,
            ]);
        }

        $user->changePassword($newHashedPassword);

        // Documented exception (D10) to the "flush only in the Messenger transaction
        // middleware" rule: this runs inside the security listener during login, where
        // no handler transaction exists. Without an immediate flush the bcrypt->argon2id
        // rehash (and the trickle-adopted hash) would silently never persist.
        $this->entityManager->flush();
    }
}
