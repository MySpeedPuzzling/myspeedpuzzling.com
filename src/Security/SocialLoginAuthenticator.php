<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Security;

use Psr\Log\LoggerInterface;
use SpeedPuzzling\Web\Entity\UserAccount;
use SpeedPuzzling\Web\Services\SocialLogin\SocialAccountResolver;
use SpeedPuzzling\Web\Services\SocialLogin\SocialLoginSettings;
use SpeedPuzzling\Web\Services\SocialLogin\SocialLoginStateStore;
use SpeedPuzzling\Web\Services\SocialLogin\SocialProfileFetcher;
use SpeedPuzzling\Web\Value\OauthFlowIntent;
use SpeedPuzzling\Web\Value\ReturnUrl;
use SpeedPuzzling\Web\Value\OauthProvider;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Exception\AuthenticationException;
use Symfony\Component\Security\Http\Authenticator\AbstractAuthenticator;
use Symfony\Component\Security\Http\Authenticator\Passport\Badge\UserBadge;
use Symfony\Component\Security\Http\Authenticator\Passport\Passport;
use Symfony\Component\Security\Http\Authenticator\Passport\Badge\RememberMeBadge;
use Symfony\Component\Security\Http\Authenticator\Passport\SelfValidatingPassport;
use Symfony\Component\Security\Http\SecurityRequestAttributes;

/**
 * Social login callback, per-provider (settled D13: adding a provider = new
 * enum case + new authenticator). Claims only login-intent callbacks - the
 * state payload says what the callback is for; link-intent callbacks (rule 5)
 * fall through to SocialLoginCallbackController, because Apple's cross-site
 * POST arrives without the session cookie that would identify the linking user.
 *
 * The OAuth state lives in cache, not the session (single-use, 10 min TTL) -
 * for Apple the state IS the CSRF protection of the POST callback.
 */
abstract class SocialLoginAuthenticator extends AbstractAuthenticator
{
    /**
     * Request attribute handing the post-login destination from authenticate()
     * (which consumes the single-use OAuth state) to onAuthenticationSuccess().
     * Request-scoped on purpose - see the write site.
     */
    private const string RETURN_URL_ATTRIBUTE = '_social_login_return_url';

    public function __construct(
        private readonly SocialLoginSettings $settings,
        private readonly SocialLoginStateStore $stateStore,
        private readonly SocialProfileFetcher $profileFetcher,
        private readonly SocialAccountResolver $accountResolver,
        private readonly UrlGeneratorInterface $urlGenerator,
        private readonly LoggerInterface $logger,
    ) {
    }

    abstract public function provider(): OauthProvider;

    public function supports(Request $request): bool
    {
        if ($request->attributes->get('_route') !== 'social_login_callback') {
            return false;
        }

        if ($request->attributes->get('provider') !== $this->provider()->value) {
            return false;
        }

        if ($this->settings->isEnabled($this->provider()) === false) {
            return false;
        }

        // Peek, never consume: the request may belong to the link-flow
        // controller. An unknown/expired state also falls through to it.
        return $this->stateStore->peekIntent(self::stateFrom($request)) === OauthFlowIntent::Login;
    }

    public function authenticate(Request $request): Passport
    {
        $flowState = $this->stateStore->consumeState(self::stateFrom($request));

        if (
            $flowState === null
            || $flowState->intent !== OauthFlowIntent::Login
            || $flowState->provider !== $this->provider()
        ) {
            // supports() saw the state a moment ago - losing it here means a
            // concurrent callback consumed it (replay); fail closed
            throw new AuthenticationException('OAuth state missing, expired or mismatched.');
        }

        $providerError = $request->query->get('error') ?? $request->request->get('error');

        if (is_string($providerError) && $providerError !== '') {
            // Typically access_denied: the user cancelled on the consent screen
            throw new AuthenticationException('Provider returned an error: ' . $providerError);
        }

        $code = $request->query->get('code') ?? $request->request->get('code');

        if (!is_string($code) || $code === '') {
            throw new AuthenticationException('Authorization code missing from the callback.');
        }

        try {
            $profile = $this->profileFetcher->fetch(
                $this->provider(),
                $code,
                $flowState->pkceVerifier,
                $this->appleUserPayload($request),
            );
        } catch (\Throwable $exception) {
            $this->logger->error('Social login code exchange failed.', [
                'exception' => $exception,
                'provider' => $this->provider()->value,
            ]);

            throw new AuthenticationException('Code exchange with the provider failed.');
        }

        $userAccount = $this->accountResolver->resolve($profile, $request->getLocale());

        // Carried on the Request, never on $this: this service outlives the
        // request in FrankenPHP worker mode, so anything stored on the instance
        // would surface in the next visitor's flow. onAuthenticationSuccess()
        // runs later in the same request and reads it back.
        $request->attributes->set(self::RETURN_URL_ATTRIBUTE, $flowState->returnUrl);

        return new SelfValidatingPassport(
            new UserBadge($userAccount->getUserIdentifier(), static fn (): UserAccount => $userAccount),
            // Without this badge the firewall's always-on remember-me silently
            // skips social sign-ins, and only password/magic-link users would
            // stay signed in for 30 days
            [new RememberMeBadge()],
        );
    }

    public function onAuthenticationSuccess(Request $request, TokenInterface $token, string $firewallName): Response
    {
        // Validated twice over: once at the start route before it entered the
        // state payload, once here. The store is server-side and single-use, so
        // this is belt-and-braces rather than a real second gate - but this
        // value becomes a Location header, and post-login is the worst place to
        // be wrong about a redirect.
        $returnUrl = ReturnUrl::tryFrom($this->carriedReturnUrl($request));

        if ($returnUrl !== null) {
            return new RedirectResponse($returnUrl->path);
        }

        return new RedirectResponse($this->urlGenerator->generate('my_profile'));
    }

    private function carriedReturnUrl(Request $request): null|string
    {
        $carried = $request->attributes->get(self::RETURN_URL_ATTRIBUTE);

        return is_string($carried) ? $carried : null;
    }

    public function onAuthenticationFailure(Request $request, AuthenticationException $exception): Response
    {
        // Rule 4 is not a failure: park-and-confirm instead of silent creation
        if ($exception instanceof SocialRegistrationRequired) {
            return new RedirectResponse($this->urlGenerator->generate(
                'social_register_confirm',
                ['token' => $exception->registrationToken],
            ));
        }

        // Same mechanism as the form login: the login page renders the error
        // from the session (generic unless a Custom*Exception carries copy)
        if ($request->hasSession()) {
            $request->getSession()->set(SecurityRequestAttributes::AUTHENTICATION_ERROR, $exception);
        }

        return new RedirectResponse($this->urlGenerator->generate('login'));
    }

    /**
     * Apple-only: the `user` JSON (name) posted alongside the very first
     * authorization. Read from the Request, never from superglobals.
     */
    protected function appleUserPayload(Request $request): null|string
    {
        return null;
    }

    private static function stateFrom(Request $request): null|string
    {
        $state = $request->query->get('state') ?? $request->request->get('state');

        return is_string($state) ? $state : null;
    }
}
