<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\MessageHandler;

use Psr\Clock\ClockInterface;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Entity\OauthIdentity;
use SpeedPuzzling\Web\Entity\Player;
use SpeedPuzzling\Web\Entity\UserAccount;
use SpeedPuzzling\Web\Exceptions\CouldNotGenerateUniqueCode;
use SpeedPuzzling\Web\Exceptions\EmailAlreadyRegistered;
use SpeedPuzzling\Web\Exceptions\OauthIdentityAlreadyLinked;
use SpeedPuzzling\Web\Exceptions\SocialLoginRestrictedToAdmins;
use SpeedPuzzling\Web\Message\RecordAuthAuditEvent;
use SpeedPuzzling\Web\Message\RegisterWithOauthIdentity;
use SpeedPuzzling\Web\Repository\OauthIdentityRepository;
use SpeedPuzzling\Web\Repository\PlayerRepository;
use SpeedPuzzling\Web\Repository\UserAccountRepository;
use SpeedPuzzling\Web\Services\AuthAuditRecorder;
use SpeedPuzzling\Web\Services\GenerateUniquePlayerCode;
use SpeedPuzzling\Web\Services\SocialLogin\SocialLoginSettings;
use SpeedPuzzling\Web\Value\AuthAuditEventType;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * Social registration (rule 4): account + player + identity row in one
 * transaction, mirroring RegisterUserHandler. The account is a native
 * `msp|<uuid7>` account with a NULL password - provenance lives only in
 * oauth_identity, and the email+password door opens the moment the user sets
 * a password (settings, or the password-reset flow since the email is
 * provider-verified).
 */
#[AsMessageHandler]
final readonly class RegisterWithOauthIdentityHandler
{
    public function __construct(
        private UserAccountRepository $userAccountRepository,
        private PlayerRepository $playerRepository,
        private OauthIdentityRepository $oauthIdentityRepository,
        private GenerateUniquePlayerCode $generateUniquePlayerCode,
        private SocialLoginSettings $socialLoginSettings,
        private ClockInterface $clock,
        private AuthAuditRecorder $authAuditRecorder,
    ) {
    }

    /**
     * @return string the new account's user_id - the caller logs the user in with it
     *
     * @throws SocialLoginRestrictedToAdmins
     * @throws EmailAlreadyRegistered
     * @throws OauthIdentityAlreadyLinked
     * @throws CouldNotGenerateUniqueCode
     */
    public function __invoke(RegisterWithOauthIdentity $message): string
    {
        // Rule-4 registration is disabled entirely during the admin-only stage:
        // a not-yet-existing account has no player to be admin (plan §Feature
        // flags + admin-only rollout stage)
        if ($this->socialLoginSettings->isAdminOnly()) {
            throw new SocialLoginRestrictedToAdmins();
        }

        if ($this->oauthIdentityRepository->findByProviderUserId($message->provider, $message->providerUserId) !== null) {
            throw new OauthIdentityAlreadyLinked();
        }

        $email = UserAccount::canonicalizeEmail($message->email);

        if ($this->userAccountRepository->findByEmail($email) !== null) {
            throw new EmailAlreadyRegistered();
        }

        // Same window-A guard as native registration: an Auth0 player without a
        // user_account row yet must not get shadowed by a fresh social account
        // on the same address (RegisterUserHandler has the full story)
        if ($this->playerRepository->findByEmail($email) !== null) {
            throw new EmailAlreadyRegistered();
        }

        $now = $this->clock->now();
        $userId = 'msp|' . Uuid::uuid7()->toString();

        $userAccount = new UserAccount(
            Uuid::uuid7(),
            $userId,
            $email,
            $now,
        );

        if ($message->emailVerified) {
            $userAccount->markEmailVerified($now);
        }

        $this->userAccountRepository->save($userAccount);

        $player = new Player(
            Uuid::uuid7(),
            $this->generateUniquePlayerCode->generate(),
            $userId,
            $email,
            $message->name,
            $now,
        );

        if ($message->locale !== null) {
            $player->changeLocale($message->locale);
        }

        $this->playerRepository->save($player);

        $this->oauthIdentityRepository->save(new OauthIdentity(
            id: Uuid::uuid7(),
            userAccount: $userAccount,
            provider: $message->provider,
            providerUserId: $message->providerUserId,
            emailAtLink: $email,
            linkedAt: $now,
            // Registering through the provider IS this identity's first use
            lastUsedAt: $now,
        ));

        $this->authAuditRecorder->record(new RecordAuthAuditEvent(
            eventType: AuthAuditEventType::OauthRegistration,
            userAccountId: $userAccount->id->toString(),
            userId: $userId,
            email: $email,
            authenticator: $message->provider->authenticatorLabel(),
        ));

        return $userId;
    }
}
