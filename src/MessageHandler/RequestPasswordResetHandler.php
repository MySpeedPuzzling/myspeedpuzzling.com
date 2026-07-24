<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\MessageHandler;

use Psr\Clock\ClockInterface;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Entity\ResetPasswordRequest;
use SpeedPuzzling\Web\Message\RequestPasswordReset;
use SpeedPuzzling\Web\Repository\ResetPasswordRequestRepository;
use SpeedPuzzling\Web\Repository\UserAccountRepository;
use SpeedPuzzling\Web\Value\PasswordResetToken;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
final readonly class RequestPasswordResetHandler
{
    public function __construct(
        private UserAccountRepository $userAccountRepository,
        private ResetPasswordRequestRepository $resetPasswordRequestRepository,
        private ClockInterface $clock,
    ) {
    }

    /**
     * Returns the plain reset token to email to the user, or null when no email may be
     * sent (unknown address or an active request already exists). The caller must respond
     * identically in both cases - the null is deliberately indistinguishable from success
     * so the endpoint cannot be used to probe which emails have an account.
     */
    public function __invoke(RequestPasswordReset $message): null|PasswordResetToken
    {
        $userAccount = $this->userAccountRepository->findByEmail($message->email);

        if ($userAccount === null) {
            return null;
        }

        $now = $this->clock->now();

        // Opportunistic garbage collection; the week of grace keeps recently expired
        // requests around so their links can still say "expired" instead of "invalid"
        $this->resetPasswordRequestRepository->removeExpiredBefore($now->modify('-1 week'));

        if ($this->resetPasswordRequestRepository->hasActiveRequestForUserAccount($userAccount, $now)) {
            return null;
        }

        $token = PasswordResetToken::generate();

        $this->resetPasswordRequestRepository->save(
            new ResetPasswordRequest(
                Uuid::uuid7(),
                $userAccount,
                $token->selector,
                $token->hashedVerifier(),
                $now,
                $now->modify(ResetPasswordRequest::LIFETIME),
            ),
        );

        return $token;
    }
}
