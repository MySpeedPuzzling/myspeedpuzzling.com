<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\MessageHandler;

use Psr\Clock\ClockInterface;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Entity\OauthIdentity;
use SpeedPuzzling\Web\Exceptions\OauthIdentityAlreadyLinked;
use SpeedPuzzling\Web\Exceptions\SocialLoginRestrictedToAdmins;
use SpeedPuzzling\Web\Exceptions\UserAccountNotFound;
use SpeedPuzzling\Web\Message\LinkOauthIdentity;
use SpeedPuzzling\Web\Message\RecordAuthAuditEvent;
use SpeedPuzzling\Web\Repository\OauthIdentityRepository;
use SpeedPuzzling\Web\Repository\UserAccountRepository;
use SpeedPuzzling\Web\Services\AuthAuditRecorder;
use SpeedPuzzling\Web\Services\SocialLogin\SocialLoginAdminOnlyGuard;
use SpeedPuzzling\Web\Value\AuthAuditEventType;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
final readonly class LinkOauthIdentityHandler
{
    public function __construct(
        private UserAccountRepository $userAccountRepository,
        private OauthIdentityRepository $oauthIdentityRepository,
        private SocialLoginAdminOnlyGuard $adminOnlyGuard,
        private ClockInterface $clock,
        private AuthAuditRecorder $authAuditRecorder,
    ) {
    }

    /**
     * @throws UserAccountNotFound
     * @throws SocialLoginRestrictedToAdmins
     * @throws OauthIdentityAlreadyLinked
     */
    public function __invoke(LinkOauthIdentity $message): void
    {
        $userAccount = $this->userAccountRepository->findByUserId($message->userId);

        if ($userAccount === null) {
            throw new UserAccountNotFound();
        }

        $this->adminOnlyGuard->assertAllowedFor($message->userId);

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

        $this->oauthIdentityRepository->save(new OauthIdentity(
            id: Uuid::uuid7(),
            userAccount: $userAccount,
            provider: $message->provider,
            providerUserId: $message->providerUserId,
            emailAtLink: $message->emailAtLink,
            linkedAt: $now,
            lastUsedAt: $message->usedForLogin ? $now : null,
        ));

        $this->authAuditRecorder->record(new RecordAuthAuditEvent(
            eventType: AuthAuditEventType::OauthIdentityLinked,
            userId: $message->userId,
            authenticator: $message->provider->authenticatorLabel(),
            metadata: ['provider' => $message->provider->value],
        ));
    }
}
