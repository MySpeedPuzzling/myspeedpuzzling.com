<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\MessageHandler;

use Psr\Clock\ClockInterface;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Entity\OauthIdentity;
use SpeedPuzzling\Web\Exceptions\OauthIdentityAlreadyLinked;
use SpeedPuzzling\Web\Exceptions\UserAccountNotFound;
use SpeedPuzzling\Web\Message\LinkOauthIdentity;
use SpeedPuzzling\Web\Message\RecordAuthAuditEvent;
use SpeedPuzzling\Web\Repository\OauthIdentityRepository;
use SpeedPuzzling\Web\Repository\UserAccountRepository;
use SpeedPuzzling\Web\Services\AuthAuditRecorder;
use SpeedPuzzling\Web\Value\AuthAuditEventType;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
final readonly class LinkOauthIdentityHandler
{
    public function __construct(
        private UserAccountRepository $userAccountRepository,
        private OauthIdentityRepository $oauthIdentityRepository,
        private ClockInterface $clock,
        private AuthAuditRecorder $authAuditRecorder,
    ) {
    }

    /**
     * @throws UserAccountNotFound
     * @throws OauthIdentityAlreadyLinked
     */
    public function __invoke(LinkOauthIdentity $message): void
    {
        $userAccount = $this->userAccountRepository->findByUserId($message->userId);

        if ($userAccount === null) {
            throw new UserAccountNotFound();
        }

        // The unique (provider, provider_user_id) constraint would catch this at
        // flush, but the advisory check turns a benign double-submit or an
        // identity already claimed by another account into a clean exception
        // instead of a broken transaction
        if ($this->oauthIdentityRepository->findByProviderUserId($message->provider, $message->providerUserId) !== null) {
            throw new OauthIdentityAlreadyLinked();
        }

        // One identity per provider per account: the settings UI offers
        // connect/disconnect per provider, a second Google identity would be
        // unreachable there
        if ($this->oauthIdentityRepository->findForUserAccount($userAccount, $message->provider) !== null) {
            throw new OauthIdentityAlreadyLinked();
        }

        $now = $this->clock->now();

        $oauthIdentity = new OauthIdentity(
            id: Uuid::uuid7(),
            userAccount: $userAccount,
            provider: $message->provider,
            providerUserId: $message->providerUserId,
            emailAtLink: $message->emailAtLink,
            linkedAt: $now,
            lastUsedAt: $message->usedForLogin ? $now : null,
        );
        // Every link path (rule-2 auto-link, settings connect, interstitial
        // connect) ends here, so every one of them mails the owner a notice
        $oauthIdentity->linkedToExistingAccount();

        // Two racing link flows both pass the checks above; the unique
        // (user_account_id, provider) index lets exactly one of them commit and
        // the loser surfaces as a UniqueConstraintViolationException at flush,
        // which the callers treat like OauthIdentityAlreadyLinked
        $this->oauthIdentityRepository->save($oauthIdentity);

        $this->authAuditRecorder->record(new RecordAuthAuditEvent(
            eventType: AuthAuditEventType::OauthIdentityLinked,
            userId: $message->userId,
            authenticator: $message->provider->authenticatorLabel(),
            metadata: ['provider' => $message->provider->value],
        ));
    }
}
