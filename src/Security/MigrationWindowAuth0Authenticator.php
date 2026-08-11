<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Security;

use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Exception\AuthenticationException;
use Symfony\Component\Security\Http\Authenticator\AuthenticatorInterface;
use Symfony\Component\Security\Http\Authenticator\Passport\Passport;

/**
 * Wraps the Auth0 bundle authenticator for the duration of the migration window
 * (issue #147) so that its failure can no longer evict a session that has
 * already authenticated the request. Deleted in Phase 6 together with the
 * authenticator it wraps.
 *
 * The bug it fixes: the Auth0 authenticator claims *every* request that carries
 * a session cookie, and since Stage B most sessions hold a native UserAccount
 * rather than Auth0 SDK credentials - so it fails on every single page view of
 * every signed-in user. On a public page that failure is harmless (it returns
 * null and anonymous browsing continues on the session token). On a page whose
 * access_control pattern is not PUBLIC_ACCESS it instead returns a redirect to
 * /login, and a response returned by any authenticator short-circuits the
 * request. The signed-in user is bounced to /login, LoginController sees them
 * already signed in and forwards to my_profile - which is exactly what made the
 * whole /admin area unreachable for admins.
 *
 * A wrapper rather than a decorator: Auth0's AuthenticationController type-hints
 * the concrete final Authenticator class, so decorating 'auth0.authenticator'
 * would break the /auth/callback and /logout controllers. Only the firewall's
 * custom_authenticators entry points here; every other consumer keeps the
 * original service.
 */
final readonly class MigrationWindowAuth0Authenticator implements AuthenticatorInterface
{
    public function __construct(
        private AuthenticatorInterface $inner,
        private TokenStorageInterface $tokenStorage,
    ) {
    }

    public function supports(Request $request): null|bool
    {
        return $this->inner->supports($request);
    }

    public function authenticate(Request $request): Passport
    {
        return $this->inner->authenticate($request);
    }

    public function createToken(Passport $passport, string $firewallName): TokenInterface
    {
        return $this->inner->createToken($passport, $firewallName);
    }

    public function onAuthenticationSuccess(Request $request, TokenInterface $token, string $firewallName): null|Response
    {
        return $this->inner->onAuthenticationSuccess($request, $token, $firewallName);
    }

    public function onAuthenticationFailure(Request $request, AuthenticationException $exception): null|Response
    {
        // ContextListener has already restored the session token by the time any
        // authenticator runs, so a token here means the visitor is signed in and
        // this failure is bookkeeping about Auth0, not an authentication verdict
        // about them. Returning null lets the request continue on the token they
        // already have; anything else signs a signed-in user out of the page.
        if ($this->tokenStorage->getToken() !== null) {
            return null;
        }

        // Genuinely unauthenticated: the bundle redirects to the bare /login. That
        // is the same destination LoginEntryPoint would produce, just without the
        // ?return= (docs/features/return-url.md) - left alone deliberately, this
        // wrapper is a bug fix and not a redesign of the window-era login flow.
        return $this->inner->onAuthenticationFailure($request, $exception);
    }
}
