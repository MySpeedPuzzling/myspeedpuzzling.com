<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\EventSubscriber;

use Psr\Clock\ClockInterface;
use SpeedPuzzling\Web\Entity\UserAccount;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\Cookie;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\Security\Core\User\UserInterface;
use Symfony\Component\Security\Http\RememberMe\RememberMeHandlerInterface;
use Symfony\Component\Security\Http\RememberMe\ResponseListener;

/**
 * Renews the two cookies that keep somebody signed in, so that "stay signed in
 * for 30 days" means 30 days since the last visit rather than 30 days since the
 * password was typed.
 *
 * Neither cookie renews itself, which is the bug this fixes:
 *
 *  - The session cookie is written by AbstractSessionListener only when
 *    `$sessionId !== $requestSessionCookieId`, i.e. exclusively when the id
 *    changes. PHP does not re-send it either - session_start() only emits
 *    Set-Cookie for an id it generated. So the browser keeps the Max-Age it was
 *    given at login, no matter how active the visitor is.
 *  - The remember-me cookie is re-issued only by
 *    SignatureRememberMeHandler::processRememberMe(), which runs only when the
 *    cookie is actually consumed - and RememberMeAuthenticator::supports()
 *    declines whenever a token already exists. While the session lives, it never
 *    slides.
 *
 * Both are minted in the same instant at login with the same 30-day lifetime, so
 * they expired together exactly 30 days later and took the visitor's login with
 * them. The server-side session row, which does slide (PdoSessionHandler rewrites
 * its expiry on every request), could not save anyone: the browser had already
 * stopped sending the id.
 *
 * Cache safety: this only ever adds cookies to responses for a request that
 * already carried a session cookie, which is precisely the case
 * AnonymousCacheHeadersSubscriber bails out of (`hasPreviousSession()`). Anonymous
 * responses - the ones that carry `public, s-maxage=60` and the ones crawlers
 * see - are never touched, so the shared-cacheability contract from #164 and the
 * SEO surface are unaffected.
 */
final readonly class SlidingLoginCookiesSubscriber implements EventSubscriberInterface
{
    /**
     * Symfony's default remember-me cookie name. config/packages/security.php
     * does not override `name`, so this is what the handler reads and writes.
     */
    public const string REMEMBER_ME_COOKIE = 'REMEMBERME';

    /** Session key holding the unix time the cookies were last renewed. */
    private const string REFRESHED_AT_KEY = '_msp_login_cookies_refreshed_at';

    /**
     * Renew at most once a day. The window is 30 days, so a day of drift costs
     * nothing, and this keeps the extra Set-Cookie header and the session write
     * it implies down to one per visitor per day instead of one per request.
     */
    private const int REFRESH_AFTER_SECONDS = 86400;

    /**
     * @param array<string, mixed> $sessionOptions
     */
    public function __construct(
        private TokenStorageInterface $tokenStorage,
        private RememberMeHandlerInterface $rememberMeHandler,
        private ClockInterface $clock,
        private array $sessionOptions,
    ) {
    }

    /**
     * @return array<string, array{0: string, 1: int}>
     */
    public static function getSubscribedEvents(): array
    {
        // Priority 10, the slot the other cookie subscribers use. It has to stay
        // above 0 so that the remember-me handler's request attribute is still
        // picked up by Symfony's RememberMe\ResponseListener, which listens at 0
        // and would otherwise have run already. It also has to stay above -1000
        // so the session is still open when the throttle key is written, and so
        // AbstractSessionListener can still overrule us - on a logout it clears
        // the session cookie, and that must win.
        return [
            KernelEvents::RESPONSE => ['onKernelResponse', 10],
        ];
    }

    public function onKernelResponse(ResponseEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $user = $this->tokenStorage->getToken()?->getUser();

        // Anonymous visitors have nothing to slide. Deliberately UserInterface and
        // not UserAccount: the ~140 remaining legacy Auth0-era sessions carry the
        // bundle's own user object, they have no remember-me cookie at all, and so
        // the session cookie is the only thing keeping them signed in - they are
        // the visitors who need it renewed most.
        if (!$user instanceof UserInterface) {
            return;
        }

        $request = $event->getRequest();

        if (!$request->hasSession()) {
            return;
        }

        $session = $request->getSession();

        if (!$session->isStarted()) {
            return;
        }

        $sessionId = $session->getId();

        // A changed id means this request logged in, logged out or migrated the
        // session; Symfony is already sending the cookie with a fresh Max-Age and
        // the remember-me listener has already minted its own. Renewing on top of
        // that would fight whatever they decided.
        if ($sessionId === '' || $sessionId !== $request->cookies->get($session->getName())) {
            return;
        }

        $now = $this->clock->now()->getTimestamp();
        $refreshedAt = $session->get(self::REFRESHED_AT_KEY);

        if (is_int($refreshedAt) && $now - $refreshedAt < self::REFRESH_AFTER_SECONDS) {
            return;
        }

        $session->set(self::REFRESHED_AT_KEY, $now);

        $event->getResponse()->headers->setCookie(
            $this->sessionCookie($request, $session->getName(), $sessionId),
        );

        // The remember-me cookie is renewed under three conditions:
        //
        // 1. A native account, because the signature hasher reads the email and
        //    password of a UserAccount and an Auth0 bundle user has neither.
        // 2. The visitor already holds the cookie. A legacy Auth0-era session
        //    never had one, and minting it here would silently extend a login the
        //    migration deliberately leaves on the session alone.
        // 3. Nothing has already ruled on the cookie in this request. A failed
        //    sign-in, a logout and a deauthenticated token each park a deletion
        //    cookie in that attribute during kernel.request, and renewing over it
        //    would resurrect a login that was just revoked. A fresh cookie parked
        //    there (a real login, or remember-me being consumed) is already
        //    correct. This is the guard RememberMeAuthenticator::supports() uses.
        if (
            $user instanceof UserAccount
            && $request->cookies->has(self::REMEMBER_ME_COOKIE)
            && !$request->attributes->has(ResponseListener::COOKIE_ATTR_NAME)
        ) {
            $this->rememberMeHandler->createRememberMeCookie($user);
        }
    }

    private function sessionCookie(Request $request, string $name, string $sessionId): Cookie
    {
        $options = $this->resolvedSessionOptions();

        $lifetime = $options['cookie_lifetime'] ?? null;
        $expires = is_numeric($lifetime) && (int) $lifetime > 0
            ? $this->clock->now()->getTimestamp() + (int) $lifetime
            // A zero lifetime means "until the browser closes"; re-sending it with
            // expiry 0 keeps that promise instead of quietly upgrading it.
            : 0;

        $secure = $options['cookie_secure'] ?? null;

        return Cookie::create(
            $name,
            $sessionId,
            $expires,
            is_string($options['cookie_path'] ?? null) ? $options['cookie_path'] : '/',
            is_string($options['cookie_domain'] ?? null) && $options['cookie_domain'] !== ''
                ? $options['cookie_domain']
                : null,
            // 'auto' is resolved by NativeSessionStorage before it reaches the ini,
            // so a leftover 'auto' here can only mean the ini was never consulted.
            $secure === 'auto' ? $request->isSecure() : (bool) $secure,
            (bool) ($options['cookie_httponly'] ?? true),
            false,
            $this->sameSite($options['cookie_samesite'] ?? null),
        );
    }

    /**
     * @return Cookie::SAMESITE_*
     */
    private function sameSite(mixed $configured): string
    {
        return match ($configured) {
            Cookie::SAMESITE_STRICT => Cookie::SAMESITE_STRICT,
            Cookie::SAMESITE_NONE => Cookie::SAMESITE_NONE,
            // Anything else - including an unset or empty value - takes the
            // framework default this app configures, rather than silently
            // downgrading the cookie to no SameSite protection at all.
            default => Cookie::SAMESITE_LAX,
        };
    }

    /**
     * Mirrors AbstractSessionListener::getSessionOptions() so the renewed cookie
     * is attribute-for-attribute the one Symfony itself would have written. The
     * live PHP cookie params are the base because that is where NativeSessionStorage
     * has already resolved things like cookie_secure: 'auto'.
     *
     * @return array<string, mixed>
     */
    private function resolvedSessionOptions(): array
    {
        $options = [];

        foreach (session_get_cookie_params() as $key => $value) {
            $options['cookie_' . $key] = $value;
        }

        foreach ($this->sessionOptions as $key => $value) {
            if ($key === 'cookie_secure' && $value === 'auto') {
                continue;
            }

            $options[$key] = $value;
        }

        return $options;
    }
}
