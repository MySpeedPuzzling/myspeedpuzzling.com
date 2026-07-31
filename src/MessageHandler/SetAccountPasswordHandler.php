<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\MessageHandler;

use Psr\Log\LoggerInterface;
use SpeedPuzzling\Web\Exceptions\UserAccountNotFound;
use SpeedPuzzling\Web\Message\RecordAuthAuditEvent;
use SpeedPuzzling\Web\Message\SetAccountPassword;
use SpeedPuzzling\Web\Repository\ResetPasswordRequestRepository;
use SpeedPuzzling\Web\Repository\UserAccountRepository;
use SpeedPuzzling\Web\Services\AuthAuditRecorder;
use SpeedPuzzling\Web\Value\AuthAuditEventType;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

#[AsMessageHandler]
final readonly class SetAccountPasswordHandler
{
    public function __construct(
        private UserAccountRepository $userAccountRepository,
        private ResetPasswordRequestRepository $resetPasswordRequestRepository,
        private UserPasswordHasherInterface $passwordHasher,
        private LoggerInterface $logger,
        private AuthAuditRecorder $authAuditRecorder,
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

        // Phase 5 exit-metric counter: post-sign-in-link password prompt completed
        $this->logger->info('Account password set after sign-in link.', [
            'user_id' => $message->userId,
            'password_prompt_completed' => true,
        ]);

        $this->authAuditRecorder->record(new RecordAuthAuditEvent(
            eventType: AuthAuditEventType::PasswordChanged,
            userId: $userAccount->userId,
            metadata: ['method' => 'set_password'],
        ));
    }
}
