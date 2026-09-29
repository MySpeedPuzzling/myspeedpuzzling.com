<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\MessageHandler;

use Psr\Log\LoggerInterface;
use SpeedPuzzling\Web\Message\ProcessAppleSignInEvent;
use SpeedPuzzling\Web\Message\RecordAuthAuditEvent;
use SpeedPuzzling\Web\Repository\OauthIdentityRepository;
use SpeedPuzzling\Web\Services\AuthAuditRecorder;
use SpeedPuzzling\Web\Value\AppleSignInEventType;
use SpeedPuzzling\Web\Value\AuthAuditEventType;
use SpeedPuzzling\Web\Value\OauthProvider;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * What an Apple-side change does to the linked identity (decision 2026-09-29):
 *
 * - account-deleted: the Apple ID is gone and its `sub` can never sign in
 *   again - the identity is removed ALWAYS, even when it was the account's
 *   only sign-in method. Keeping a dead credential would not keep anyone's
 *   access (the e-mailed sign-in link is what still works), so the ≥1 method
 *   invariant cannot be served here; that case is logged at warning, because
 *   an account whose address is an Apple relay may now be unreachable.
 * - consent-revoked: the user disconnected us in their Apple ID settings. The
 *   identity is removed like a settings "Disconnect" - unless it is the only
 *   sign-in method: then it stays (the invariant holds), Apple itself refuses
 *   to sign the user in without new consent, and when they consent again Apple
 *   hands back the same `sub`, which rule 1 signs straight into this account
 *   instead of risking a second one.
 * - email-disabled / email-enabled: forwarding of the relay address was
 *   switched off/on. Nothing is stored and nothing acts on it (mail to a
 *   disabled relay is simply dropped by Apple); info log only.
 *
 * Idempotent: Apple may deliver a notification more than once.
 */
#[AsMessageHandler]
final readonly class ProcessAppleSignInEventHandler
{
    public function __construct(
        private OauthIdentityRepository $oauthIdentityRepository,
        private AuthAuditRecorder $authAuditRecorder,
        private LoggerInterface $logger,
    ) {
    }

    public function __invoke(ProcessAppleSignInEvent $message): void
    {
        $oauthIdentity = $this->oauthIdentityRepository->findByProviderUserId(OauthProvider::Apple, $message->providerUserId);

        if ($oauthIdentity === null) {
            $this->logger->info('Apple sign-in notification for an identity we do not have.', [
                'event' => $message->type->value,
            ]);

            return;
        }

        $userAccount = $oauthIdentity->userAccount;

        if ($message->type === AppleSignInEventType::EmailDisabled || $message->type === AppleSignInEventType::EmailEnabled) {
            $this->logger->info('Apple relay e-mail forwarding changed.', [
                'event' => $message->type->value,
                'user_id' => $userAccount->userId,
            ]);

            return;
        }

        $isLastSignInMethod = $userAccount->password === null
            && $this->oauthIdentityRepository->countForUserAccount($userAccount) <= 1;

        if ($message->type === AppleSignInEventType::ConsentRevoked && $isLastSignInMethod) {
            $this->logger->info('Apple consent revoked; identity kept - it is the only sign-in method of the account.', [
                'user_id' => $userAccount->userId,
            ]);

            return;
        }

        if ($isLastSignInMethod) {
            $this->logger->warning('Apple ID deleted: the account has no password and no other provider left - only the e-mailed sign-in link can reach it.', [
                'user_id' => $userAccount->userId,
            ]);
        }

        $this->oauthIdentityRepository->remove($oauthIdentity);

        $this->authAuditRecorder->record(new RecordAuthAuditEvent(
            eventType: AuthAuditEventType::OauthIdentityUnlinked,
            userId: $userAccount->userId,
            authenticator: OauthProvider::Apple->authenticatorLabel(),
            metadata: [
                'provider' => OauthProvider::Apple->value,
                'source' => 'apple_server_notification',
                'event' => $message->type->value,
            ],
        ));
    }
}
