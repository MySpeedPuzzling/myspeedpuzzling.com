<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\MessageHandler;

use Psr\Clock\ClockInterface;
use SpeedPuzzling\Web\Entity\UserAccount;
use SpeedPuzzling\Web\Exceptions\EmailVerificationTokenExpired;
use SpeedPuzzling\Web\Exceptions\InvalidEmailVerificationToken;
use SpeedPuzzling\Web\Message\VerifyEmail;
use SpeedPuzzling\Web\Repository\UserAccountRepository;
use SpeedPuzzling\Web\Services\EmailVerificationTokenSigner;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
final readonly class VerifyEmailHandler
{
    public function __construct(
        private EmailVerificationTokenSigner $tokenSigner,
        private UserAccountRepository $userAccountRepository,
        private ClockInterface $clock,
    ) {
    }

    /**
     * @throws InvalidEmailVerificationToken
     * @throws EmailVerificationTokenExpired
     */
    public function __invoke(VerifyEmail $message): void
    {
        $claim = $this->tokenSigner->parse($message->token);
        $userAccount = $this->userAccountRepository->findByUserId($claim->userId);

        if (
            $userAccount === null
            || $userAccount->email !== UserAccount::canonicalizeEmail($claim->email)
        ) {
            // Account gone, or the address changed after the link was sent - the link
            // no longer proves ownership of the account's current email
            throw new InvalidEmailVerificationToken();
        }

        $userAccount->markEmailVerified($this->clock->now());
    }
}
