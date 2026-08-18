<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\MessageHandler;

use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Entity\AuthAuditEvent;
use SpeedPuzzling\Web\Entity\UserAccount;
use SpeedPuzzling\Web\Message\RecordAuthAuditEvent;
use SpeedPuzzling\Web\Repository\AuthAuditEventRepository;
use SpeedPuzzling\Web\Repository\UserAccountRepository;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
final readonly class RecordAuthAuditEventHandler
{
    private const int USER_AGENT_MAX_LENGTH = 500;

    public function __construct(
        private AuthAuditEventRepository $authAuditEventRepository,
        private UserAccountRepository $userAccountRepository,
        private EntityManagerInterface $entityManager,
        private RequestStack $requestStack,
        private ClockInterface $clock,
    ) {
    }

    public function __invoke(RecordAuthAuditEvent $message): void
    {
        // Lookup only, never create: a failed login for an unknown email stays
        // an account-less row identified by the attempted address
        $userAccount = null;

        if ($message->userAccountId !== null) {
            // Identity-map hit for an account persisted in the same unflushed
            // transaction (registration) - a DB lookup could not see it yet
            $userAccount = $this->entityManager->getReference(UserAccount::class, Uuid::fromString($message->userAccountId));
        }

        if ($userAccount === null && $message->userId !== null) {
            $userAccount = $this->userAccountRepository->findByUserId($message->userId);
        }

        if ($userAccount === null && $message->email !== null) {
            $userAccount = $this->userAccountRepository->findByEmail($message->email);
        }

        $email = $message->email ?? $userAccount?->email;

        // The message runs sync, so dispatch sites without a Request in hand
        // (Messenger handlers) still get the current request's IP + user agent
        $request = $this->requestStack->getCurrentRequest();
        $ipAddress = $message->ipAddress ?? $request?->getClientIp();
        $userAgent = $message->userAgent ?? $request?->headers->get('User-Agent');

        $this->authAuditEventRepository->save(new AuthAuditEvent(
            id: Uuid::uuid7(),
            userAccount: $userAccount,
            email: $email === null ? null : UserAccount::canonicalizeEmail($email),
            eventType: $message->eventType,
            authenticator: $message->authenticator,
            ipAddress: $ipAddress,
            userAgent: $userAgent === null ? null : mb_substr($userAgent, 0, self::USER_AGENT_MAX_LENGTH),
            metadata: $message->metadata,
            occurredAt: $this->clock->now(),
        ));
    }
}
