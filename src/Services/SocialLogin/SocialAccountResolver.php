<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Services\SocialLogin;

use Psr\Log\LoggerInterface;
use SpeedPuzzling\Web\Entity\UserAccount;
use SpeedPuzzling\Web\Exceptions\SocialLoginRestrictedToAdmins;
use SpeedPuzzling\Web\Message\LinkOauthIdentity;
use SpeedPuzzling\Web\Message\MarkOauthIdentityUsed;
use SpeedPuzzling\Web\Repository\OauthIdentityRepository;
use SpeedPuzzling\Web\Repository\UserAccountRepository;
use SpeedPuzzling\Web\Security\SocialRegistrationRequired;
use SpeedPuzzling\Web\Value\SocialUserProfile;
use Symfony\Component\Messenger\Exception\HandlerFailedException;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Security\Core\Exception\AuthenticationException;
use Symfony\Component\Security\Core\Exception\CustomUserMessageAuthenticationException;

/**
 * The five settled account-linking rules (D13), applied to a provider-proven
 * profile during login. Rules 1-4 live here (rule 5 - explicit linking from
 * settings - has its own controller). Login errors stay deliberately generic:
 * which sign-in methods an account has must never leak (settled
 * anti-enumeration rule).
 */
final readonly class SocialAccountResolver
{
    // Error messages double as translation keys in the `security` domain,
    // rendered on the login page - same pattern as LoginFormAuthenticator
    public const string ERROR_SIGN_IN_AND_CONNECT = 'An account with this email address already exists. Sign in with your password first, then connect %provider% in your profile settings.';
    public const string ERROR_NO_EMAIL = '%provider% did not share an email address with us, so we cannot sign you in this way. Please sign in another way.';

    public function __construct(
        private OauthIdentityRepository $oauthIdentityRepository,
        private UserAccountRepository $userAccountRepository,
        private SocialLoginSettings $settings,
        private SocialLoginAdminOnlyGuard $adminOnlyGuard,
        private SocialLoginStateStore $stateStore,
        private MessageBusInterface $messageBus,
        private LoggerInterface $logger,
    ) {
    }

    /**
     * @param null|string $locale carried into the parked rule-4 registration so the
     *        new player keeps the language they were browsing in
     *
     * @throws AuthenticationException also its SocialRegistrationRequired subclass,
     *         which the authenticator turns into the interstitial redirect
     */
    public function resolve(SocialUserProfile $profile, null|string $locale): UserAccount
    {
        $provider = $profile->provider;

        // Rule 1: known identity -> log in, touch last_used_at
        $oauthIdentity = $this->oauthIdentityRepository->findByProviderUserId($provider, $profile->providerUserId);

        if ($oauthIdentity !== null) {
            $userAccount = $oauthIdentity->userAccount;
            $this->assertAdminAllowed($userAccount->userId);

            $this->messageBus->dispatch(new MarkOauthIdentityUsed($provider, $profile->providerUserId));

            return $userAccount;
        }

        if ($profile->email !== null) {
            $userAccount = $this->userAccountRepository->findByEmail($profile->email);

            if ($userAccount !== null) {
                // Rule 3: matching account but the provider did not verify the
                // address - auto-linking would hand the account to whoever
                // typed this email at the provider (account-takeover guard)
                if ($profile->emailVerified === false) {
                    throw new CustomUserMessageAuthenticationException(
                        self::ERROR_SIGN_IN_AND_CONNECT,
                        ['%provider%' => $provider->displayName()],
                    );
                }

                // Rule 2: provider-verified email matches -> auto-link + log in
                $this->assertAdminAllowed($userAccount->userId);

                try {
                    $this->messageBus->dispatch(new LinkOauthIdentity(
                        userId: $userAccount->userId,
                        provider: $provider,
                        providerUserId: $profile->providerUserId,
                        emailAtLink: $profile->email,
                        usedForLogin: true,
                    ));
                } catch (HandlerFailedException $exception) {
                    // E.g. the account already carries a DIFFERENT identity of
                    // this provider; naming the reason would leak which methods
                    // the account has, so the failure stays generic
                    $this->logger->warning('Social login auto-link (rule 2) refused.', [
                        'exception' => $exception,
                        'provider' => $provider->value,
                    ]);

                    throw new AuthenticationException('Auto-link refused.');
                }

                return $userAccount;
            }
        }

        // Rule 4: no match. Registration is disabled entirely while admin-only
        // (an account that does not exist yet has no player to be admin).
        if ($this->settings->isAdminOnly()) {
            throw new AuthenticationException('Social registration is disabled during the admin-only stage.');
        }

        if ($profile->email === null) {
            throw new CustomUserMessageAuthenticationException(
                self::ERROR_NO_EMAIL,
                ['%provider%' => $provider->displayName()],
            );
        }

        // Never silent creation - park the profile and let the interstitial ask
        throw new SocialRegistrationRequired($this->stateStore->parkRegistration($profile, $locale));
    }

    private function assertAdminAllowed(string $userId): void
    {
        try {
            $this->adminOnlyGuard->assertAllowedFor($userId);
        } catch (SocialLoginRestrictedToAdmins) {
            // Generic on purpose: while admin-only, the feature must not reveal
            // itself to non-admin accounts
            throw new AuthenticationException('Social login denied.');
        }
    }
}
