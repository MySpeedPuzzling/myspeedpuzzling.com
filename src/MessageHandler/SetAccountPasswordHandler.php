<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\MessageHandler;

use SpeedPuzzling\Web\Exceptions\UserAccountNotFound;
use SpeedPuzzling\Web\Message\SetAccountPassword;
use SpeedPuzzling\Web\Repository\ResetPasswordRequestRepository;
use SpeedPuzzling\Web\Repository\UserAccountRepository;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

#[AsMessageHandler]
final readonly class SetAccountPasswordHandler
{
    public function __construct(
        private UserAccountRepository $userAccountRepository,
        private ResetPasswordRequestRepository $resetPasswordRequestRepository,
        private UserPasswordHasherInterface $passwordHasher,
    ) {
    }

    /**
     * @throws UserAccountNotFound
     */
    public function __invoke(SetAccountPassword $message): void
    {
        $userAccount = $this->userAccountRepository->findByUserId($message->userId);

        if ($userAccount === null) {
            throw new UserAccountNotFound();
        }

        $userAccount->changePassword(
            $this->passwordHasher->hashPassword($userAccount, $message->plainPassword),
        );

        // Anything that was still outstanding for the old password dies with it:
        // reset requests explicitly, sign-in links implicitly (the login link
        // signature covers the password, see config/packages/security.php)
        $this->resetPasswordRequestRepository->removeAllForUserAccount($userAccount);
    }
}
