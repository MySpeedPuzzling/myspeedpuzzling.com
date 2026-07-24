<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\MessageHandler;

use SpeedPuzzling\Web\Entity\UserAccount;
use SpeedPuzzling\Web\Exceptions\CurrentPasswordDoesNotMatch;
use SpeedPuzzling\Web\Exceptions\EmailAlreadyRegistered;
use SpeedPuzzling\Web\Exceptions\UserAccountNotFound;
use SpeedPuzzling\Web\Message\ChangeAccountEmail;
use SpeedPuzzling\Web\Repository\PlayerRepository;
use SpeedPuzzling\Web\Repository\ResetPasswordRequestRepository;
use SpeedPuzzling\Web\Repository\UserAccountRepository;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * Change the account's email address (issue #147). The address is the login
 * identifier and the destination of every rescue link, so changing it needs the
 * current password - a hijacked session must not be able to take the account
 * away from its owner.
 *
 * The new address starts unverified (UserAccount::changeEmail resets
 * email_verified_at) and any outstanding verification link dies with the old
 * address, since the token binds the address it was issued for. The caller sends
 * a fresh one.
 */
#[AsMessageHandler]
final readonly class ChangeAccountEmailHandler
{
    public function __construct(
        private UserAccountRepository $userAccountRepository,
        private PlayerRepository $playerRepository,
        private ResetPasswordRequestRepository $resetPasswordRequestRepository,
        private UserPasswordHasherInterface $passwordHasher,
    ) {
    }

    /**
     * @throws UserAccountNotFound
     * @throws CurrentPasswordDoesNotMatch
     * @throws EmailAlreadyRegistered
     */
    public function __invoke(ChangeAccountEmail $message): void
    {
        $userAccount = $this->userAccountRepository->findByUserId($message->userId);

        if ($userAccount === null) {
            throw new UserAccountNotFound();
        }

        if ($userAccount->password === null) {
            throw new CurrentPasswordDoesNotMatch();
        }

        if (!$this->passwordHasher->isPasswordValid($userAccount, $message->currentPassword)) {
            throw new CurrentPasswordDoesNotMatch();
        }

        $newEmail = UserAccount::canonicalizeEmail($message->newEmail);

        if ($newEmail === $userAccount->email) {
            return;
        }

        if ($this->userAccountRepository->findByEmail($newEmail) !== null) {
            throw new EmailAlreadyRegistered();
        }

        // Same reasoning as registration: an address that already reaches a player -
        // including a legacy Auth0 one with no user_account row yet - must not be
        // claimed by a second account, or the Stage B import would strand it
        $playerOnNewEmail = $this->playerRepository->findByEmail($newEmail);

        if ($playerOnNewEmail !== null && $playerOnNewEmail->userId !== $userAccount->userId) {
            throw new EmailAlreadyRegistered();
        }

        $userAccount->changeEmail($newEmail);

        // A reset link already on its way to the OLD address must die with it. Losing
        // control of that mailbox is one of the reasons people change their address,
        // and reset tokens are bound to the account rather than to the address - so
        // without this, whoever holds the old inbox keeps an hour-long way back in.
        $this->resetPasswordRequestRepository->removeAllForUserAccount($userAccount);

        // The player row carries its own copy - it is what notification emails are sent
        // to - so the two must not drift apart
        $player = $this->playerRepository->findByUserId($userAccount->userId);

        if ($player !== null) {
            $player->changeEmail($newEmail);
        }
    }
}
