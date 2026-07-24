<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\MessageHandler;

use SpeedPuzzling\Web\Exceptions\CurrentPasswordDoesNotMatch;
use SpeedPuzzling\Web\Exceptions\UserAccountNotFound;
use SpeedPuzzling\Web\Message\ChangeAccountPassword;
use SpeedPuzzling\Web\Repository\ResetPasswordRequestRepository;
use SpeedPuzzling\Web\Repository\UserAccountRepository;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * Native change-password (issue #147) - replaces the #161 flow that asked Auth0
 * to send a reset email.
 *
 * The current password is required: a hijacked session must not be enough to
 * lock the owner out. An imported bcrypt hash verifies here exactly as it does
 * at login (the migrating hasher handles both algorithms), and the new password
 * lands as argon2id.
 */
#[AsMessageHandler]
final readonly class ChangeAccountPasswordHandler
{
    public function __construct(
        private UserAccountRepository $userAccountRepository,
        private ResetPasswordRequestRepository $resetPasswordRequestRepository,
        private UserPasswordHasherInterface $passwordHasher,
    ) {
    }

    /**
     * @throws UserAccountNotFound
     * @throws CurrentPasswordDoesNotMatch
     */
    public function __invoke(ChangeAccountPassword $message): void
    {
        $userAccount = $this->userAccountRepository->findByUserId($message->userId);

        if ($userAccount === null) {
            throw new UserAccountNotFound();
        }

        // No local hash yet (a legacy account that has only ever been verified through
        // the trickle branch): there is nothing to check the current password against,
        // so this door stays shut - the sign-in link and the reset flow are the way in
        if ($userAccount->password === null) {
            throw new CurrentPasswordDoesNotMatch();
        }

        if (!$this->passwordHasher->isPasswordValid($userAccount, $message->currentPassword)) {
            throw new CurrentPasswordDoesNotMatch();
        }

        $userAccount->changePassword(
            $this->passwordHasher->hashPassword($userAccount, $message->newPassword),
        );

        // Whatever was outstanding for the old password dies with it: reset requests
        // explicitly, sign-in links implicitly (their signature covers the password)
        $this->resetPasswordRequestRepository->removeAllForUserAccount($userAccount);
    }
}
