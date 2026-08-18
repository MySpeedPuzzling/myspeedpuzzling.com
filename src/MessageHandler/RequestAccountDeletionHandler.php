<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\MessageHandler;

use Psr\Clock\ClockInterface;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Entity\AccountDeletionRequest;
use SpeedPuzzling\Web\Exceptions\UserAccountNotFound;
use SpeedPuzzling\Web\Message\RecordAuthAuditEvent;
use SpeedPuzzling\Web\Message\RequestAccountDeletion;
use SpeedPuzzling\Web\Repository\AccountDeletionRequestRepository;
use SpeedPuzzling\Web\Repository\UserAccountRepository;
use SpeedPuzzling\Web\Services\AuthAuditRecorder;
use SpeedPuzzling\Web\Value\AccountDeletionToken;
use SpeedPuzzling\Web\Value\AuthAuditEventType;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * Mints the "delete my account" confirmation token. Only the row is written
 * here - the mail goes out from SendAccountDeletionLinkHandler in its own
 * transaction, so a link can never arrive for a request that failed to persist.
 */
#[AsMessageHandler]
final readonly class RequestAccountDeletionHandler
{
    public function __construct(
        private UserAccountRepository $userAccountRepository,
        private AccountDeletionRequestRepository $accountDeletionRequestRepository,
        private ClockInterface $clock,
        private AuthAuditRecorder $authAuditRecorder,
    ) {
    }

    /**
     * Returns the plain token to e-mail to the user.
     *
     * @throws UserAccountNotFound
     */
    public function __invoke(RequestAccountDeletion $message): AccountDeletionToken
    {
        $userAccount = $this->userAccountRepository->findByUserId($message->userId);

        if ($userAccount === null) {
            throw new UserAccountNotFound();
        }

        $now = $this->clock->now();

        // Opportunistic garbage collection; the week of grace keeps recently expired
        // requests around so their links can still say "expired" instead of "invalid"
        $this->accountDeletionRequestRepository->removeExpiredBefore($now->modify('-1 week'));

        // Unlike password reset there is no silent throttle here: the caller is signed
        // in, so nothing can be enumerated, and "the mail did not arrive, send another"
        // must just work. A new request replaces the older ones - exactly one link is
        // live per account, the latest. Mail volume is the rate limiter's job.
        $this->accountDeletionRequestRepository->removeAllForUserAccount($userAccount);

        $token = AccountDeletionToken::generate();

        $this->accountDeletionRequestRepository->save(
            new AccountDeletionRequest(
                Uuid::uuid7(),
                $userAccount,
                $token->selector,
                $token->hashedVerifier(),
                $now,
                $now->modify(AccountDeletionRequest::LIFETIME),
            ),
        );

        $this->authAuditRecorder->record(new RecordAuthAuditEvent(
            eventType: AuthAuditEventType::AccountDeletionRequested,
            userId: $userAccount->userId,
        ));

        return $token;
    }
}
