<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\MessageHandler;

use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Entity\UserAccount;
use SpeedPuzzling\Web\Message\ImportAuth0User;
use SpeedPuzzling\Web\Repository\PlayerRepository;
use SpeedPuzzling\Web\Repository\UserAccountRepository;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
final readonly class ImportAuth0UserHandler
{
    public function __construct(
        private UserAccountRepository $userAccountRepository,
        private PlayerRepository $playerRepository,
        private ClockInterface $clock,
        private LoggerInterface $logger,
    ) {
    }

    public function __invoke(ImportAuth0User $message): void
    {
        $now = $this->clock->now();

        $conflictingAccount = $this->userAccountRepository->findByEmail($message->email);

        if ($conflictingAccount !== null && $conflictingAccount->userId !== $message->userId) {
            // Duplicate-email pairs are expected (7 known cases) and resolved manually
            // in the reconciliation step - the import must keep running past them.
            $this->logger->warning('Auth0 import: skipping user, email already belongs to another account', [
                'user_id' => $message->userId,
                'conflicting_user_id' => $conflictingAccount->userId,
            ]);

            return;
        }

        $userAccount = $this->userAccountRepository->findByUserId($message->userId);

        if ($userAccount === null) {
            $userAccount = new UserAccount(
                Uuid::uuid7(),
                $message->userId,
                $message->email,
                $message->registeredAt ?? $now,
            );

            $this->userAccountRepository->save($userAccount);
        }

        $userAccount->applyAuth0Import(
            email: $message->email,
            bcryptPasswordHash: $message->passwordHash,
            emailVerified: $message->emailVerified,
            now: $now,
        );

        $player = $this->playerRepository->findByUserId($message->userId);
        $player?->backfillFromAuth0Import($userAccount->email, $message->name);
    }
}
