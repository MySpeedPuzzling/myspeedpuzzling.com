<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Services\SocialLogin;

use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Psr\Log\LoggerInterface;
use SpeedPuzzling\Web\Entity\UserAccount;
use SpeedPuzzling\Web\Message\LinkOauthIdentity;
use SpeedPuzzling\Web\Message\MarkOauthIdentityUsed;
use SpeedPuzzling\Web\Repository\OauthIdentityRepository;
use SpeedPuzzling\Web\Repository\UserAccountRepository;
use SpeedPuzzling\Web\Security\SocialLoginFailed;
use SpeedPuzzling\Web\Security\SocialRegistrationRequired;
use SpeedPuzzling\Web\Value\SocialLoginFailureReason;
use SpeedPuzzling\Web\Value\SocialUserProfile;
use Symfony\Component\Messenger\Exception\HandlerFailedException;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * The five settled account-linking rules (D13), applied to a provider-proven
 * profile during login. Rules 1-4 live here (rule 5 - explicit linking from
 * settings - has its own controller). Login errors stay deliberately generic:
 * which sign-in methods an account has must never leak (settled
 * anti-enumeration rule) - every account-dependent refusal shares one message,
 * only the audit log's reason code tells them apart (SocialLoginFailureReason).
 */
final readonly class SocialAccountResolver
{
    public function __construct(
        private OauthIdentityRepository $oauthIdentityRepository,
        private UserAccountRepository $userAccountRepository,
        private SocialLoginStateStore $stateStore,
        private MessageBusInterface $messageBus,
        private LoggerInterface $logger,
    ) {
    }

    /**
     * @param null|string $locale carried into the parked rule-4 registration so the
     *        new player keeps the language they were browsing in
     *
     * @throws SocialLoginFailed friendly copy for /login, reason code for the audit log
     * @throws SocialRegistrationRequired which the authenticator turns into the
     *         interstitial redirect
     */
    public function resolve(SocialUserProfile $profile, null|string $locale): UserAccount
    {
        $provider = $profile->provider;

        // Rule 1: known identity -> log in, touch last_used_at
        $oauthIdentity = $this->oauthIdentityRepository->findByProviderUserId($provider, $profile->providerUserId);

        if ($oauthIdentity !== null) {
            $userAccount = $oauthIdentity->userAccount;

            $this->messageBus->dispatch(new MarkOauthIdentityUsed($provider, $profile->providerUserId));

            return $userAccount;
        }

        if ($profile->email !== null) {
            $userAccount = $this->userAccountRepository->findByEmail($profile->email);

            if ($userAccount !== null) {
                // Rule 3: an email match alone proves nothing unless BOTH sides
                // verified the address. Provider-unverified: auto-linking would
                // hand the account to whoever typed this email at the provider.
                // MSP-unverified: whoever registered here with someone else's
                // address (never confirming it) would get that person's future
                // Google/Apple/Facebook sign-in - or the other way round
                // (account-takeover guards, decision 2026-09-29).
                if ($profile->emailVerified === false || $userAccount->emailVerifiedAt === null) {
                    throw new SocialLoginFailed(
                        $profile->emailVerified
                            ? SocialLoginFailureReason::AccountEmailUnverified
                            : SocialLoginFailureReason::ProviderEmailUnverified,
                        $provider,
                    );
                }

                // Rule 2: provider-verified email matches a verified account ->
                // auto-link + log in (the owner gets a security notice mail)
                try {
                    $this->messageBus->dispatch(new LinkOauthIdentity(
                        userId: $userAccount->userId,
                        provider: $provider,
                        providerUserId: $profile->providerUserId,
                        emailAtLink: $profile->email,
                        usedForLogin: true,
                    ));
                } catch (HandlerFailedException | UniqueConstraintViolationException $exception) {
                    // E.g. the account already carries a DIFFERENT identity of
                    // this provider (the handler's check, or the unique
                    // (user_account_id, provider) index when two callbacks race);
                    // naming the reason would leak which methods the account
                    // has, so the failure stays generic
                    $this->logger->warning('Social login auto-link (rule 2) refused.', [
                        'exception' => $exception,
                        'provider' => $provider->value,
                    ]);

                    throw new SocialLoginFailed(SocialLoginFailureReason::AutoLinkRefused, $provider);
                }

                return $userAccount;
            }
        }

        // Rule 4: no match -> registration via the interstitial
        if ($profile->email === null) {
            throw new SocialLoginFailed(SocialLoginFailureReason::NoEmail, $provider);
        }

        // Never silent creation - park the profile and let the interstitial ask
        throw new SocialRegistrationRequired($this->stateStore->parkRegistration($profile, $locale));
    }
}
