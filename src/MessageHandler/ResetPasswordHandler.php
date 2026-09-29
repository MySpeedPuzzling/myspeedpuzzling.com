<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\MessageHandler;

use SpeedPuzzling\Web\Exceptions\InvalidPasswordResetToken;
use SpeedPuzzling\Web\Exceptions\PasswordResetTokenExpired;
use SpeedPuzzling\Web\Message\RecordAuthAuditEvent;
use SpeedPuzzling\Web\Message\ResetPassword;
use SpeedPuzzling\Web\Repository\ResetPasswordRequestRepository;
use SpeedPuzzling\Web\Services\AuthAuditRecorder;
use SpeedPuzzling\Web\Services\ValidatePasswordResetToken;
use SpeedPuzzling\Web\Value\AuthAuditEventType;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

#[AsMessageHandler]
final readonly class ResetPasswordHandler
{
    public function __construct(
        private ValidatePasswordResetToken $validatePasswordResetToken,
        private ResetPasswordRequestRepository $resetPasswordRequestRepository,
        private UserPasswordHasherInterface $passwordHasher,
        private AuthAuditRecorder $authAuditRecorder,
    ) {
    }

    /**
     * @throws InvalidPasswordResetToken
     * @throws PasswordResetTokenExpired
     *
     * @return string the account's user id, so the caller can sign the browser in
     */
    public function __invoke(ResetPassword $message): string
    {
        $userAccount = $this->validatePasswordResetToken->validate($message->token);

        $userAccount->changePassword(
            $this->passwordHasher->hashPassword($userAccount, $message->plainPassword),
        );

        // Single use: consume this token and invalidate every other open request for the account
        $this->resetPasswordRequestRepository->removeAllForUserAccount($userAccount);

        $this->authAuditRecorder->record(new RecordAuthAuditEvent(
            eventType: AuthAuditEventType::PasswordResetCompleted,
            userId: $userAccount->userId,
        ));

        return $userAccount->userId;
    }
}
