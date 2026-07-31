<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\MessageHandler;

use SpeedPuzzling\Web\Exceptions\CannotUnlinkLastSignInMethod;
use SpeedPuzzling\Web\Exceptions\OauthIdentityNotFound;
use SpeedPuzzling\Web\Exceptions\SocialLoginRestrictedToAdmins;
use SpeedPuzzling\Web\Exceptions\UserAccountNotFound;
use SpeedPuzzling\Web\Message\RecordAuthAuditEvent;
use SpeedPuzzling\Web\Message\UnlinkOauthIdentity;
use SpeedPuzzling\Web\Repository\OauthIdentityRepository;
use SpeedPuzzling\Web\Repository\UserAccountRepository;
use SpeedPuzzling\Web\Services\AuthAuditRecorder;
use SpeedPuzzling\Web\Services\SocialLogin\SocialLoginAdminOnlyGuard;
use SpeedPuzzling\Web\Value\AuthAuditEventType;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
final readonly class UnlinkOauthIdentityHandler
{
    public function __construct(
        private UserAccountRepository $userAccountRepository,
        private OauthIdentityRepository $oauthIdentityRepository,
        private SocialLoginAdminOnlyGuard $adminOnlyGuard,
        private AuthAuditRecorder $authAuditRecorder,
    ) {
    }

    /**
     * @throws UserAccountNotFound
     * @throws SocialLoginRestrictedToAdmins
     * @throws OauthIdentityNotFound
     * @throws CannotUnlinkLastSignInMethod
     */
    public function __invoke(UnlinkOauthIdentity $message): void
    {
        $userAccount = $this->userAccountRepository->findByUserId($message->userId);

        if ($userAccount === null) {
            throw new UserAccountNotFound();
        }

        $this->adminOnlyGuard->assertAllowedFor($message->userId);

        $oauthIdentity = $this->oauthIdentityRepository->findForUserAccount($userAccount, $message->provider);

        if ($oauthIdentity === null) {
            throw new OauthIdentityNotFound();
        }

        // The ≥1-sign-in-method invariant (settled, D13): password IS NOT NULL
        // OR ≥1 oauth_identity must hold after the unlink. There is no
        // remove-password door in the codebase, so this is the only place the
        // invariant can break.
        if ($userAccount->password === null && $this->oauthIdentityRepository->countForUserAccount($userAccount) <= 1) {
            throw new CannotUnlinkLastSignInMethod();
        }

        $this->oauthIdentityRepository->remove($oauthIdentity);

        $this->authAuditRecorder->record(new RecordAuthAuditEvent(
            eventType: AuthAuditEventType::OauthIdentityUnlinked,
            userId: $message->userId,
            authenticator: $message->provider->authenticatorLabel(),
            metadata: ['provider' => $message->provider->value],
        ));
    }
}
