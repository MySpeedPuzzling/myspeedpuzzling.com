<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Services;

use Psr\Clock\ClockInterface;
use SensitiveParameter;
use SpeedPuzzling\Web\Entity\UserAccount;
use SpeedPuzzling\Web\Exceptions\AccountDeletionTokenExpired;
use SpeedPuzzling\Web\Exceptions\InvalidAccountDeletionToken;
use SpeedPuzzling\Web\Repository\AccountDeletionRequestRepository;
use SpeedPuzzling\Web\Value\AccountDeletionToken;

readonly final class ValidateAccountDeletionToken
{
    public function __construct(
        private AccountDeletionRequestRepository $accountDeletionRequestRepository,
        private ClockInterface $clock,
    ) {
    }

    /**
     * @throws InvalidAccountDeletionToken
     * @throws AccountDeletionTokenExpired
     */
    public function validate(#[SensitiveParameter] string $token): UserAccount
    {
        $deletionToken = AccountDeletionToken::fromString($token);
        $request = $this->accountDeletionRequestRepository->findBySelector($deletionToken->selector);

        if ($request === null) {
            throw new InvalidAccountDeletionToken();
        }

        if (!hash_equals($request->hashedVerifier, $deletionToken->hashedVerifier())) {
            throw new InvalidAccountDeletionToken();
        }

        if ($request->isExpired($this->clock->now())) {
            throw new AccountDeletionTokenExpired();
        }

        return $request->userAccount;
    }
}
