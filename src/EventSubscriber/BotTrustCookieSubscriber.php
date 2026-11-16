<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\EventSubscriber;

use DateTimeImmutable;
use SpeedPuzzling\Web\Entity\UserAccount;
use SpeedPuzzling\Web\Query\GetBotTrustEligibility;
use SpeedPuzzling\Web\Services\BotTrustCookieSigner;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\Cookie;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\Security\Http\Event\LogoutEvent;

/**
 * Mints the __bb_trust cookie (see BotTrustCookieSigner) on authenticated
 * responses of ELIGIBLE accounts (GetBotTrustEligibility). Listening on
 * RESPONSE instead of LoginSuccessEvent covers every path at once — form
 * login, all social logins, magic link, remember-me re-auth AND long-lived
 * sessions (they never fire a login event again, but they hit this listener
 * on their next page load).
 *
 * The eligibility query runs only when the cookie has to be minted or
 * refreshed (absent, invalid, foreign, older than REFRESH_AFTER_DAYS or issued
 * before TRUST_REVALIDATE_ISSUED_BEFORE) - a fresh valid cookie costs nothing.
 * An ineligible account gets no cookie, and one it carries is expired.
 * Signing out expires it too (LogoutEvent).
 *
 * Only a UserAccount (the `main` firewall) is considered: API users
 * (PatUser, OAuth2User) authenticate every call with their token and have no
 * browser to trust.
 *
 * Cache safety: the cookie is only ever added for AUTHENTICATED responses,
 * which AnonymousCacheHeadersSubscriber never marks shared-cacheable — the
 * anonymous s-maxage contract is untouched. The logout response is a redirect.
 */
final readonly class BotTrustCookieSubscriber implements EventSubscriberInterface
{
    public function __construct(
        private BotTrustCookieSigner $signer,
        private TokenStorageInterface $tokenStorage,
        private GetBotTrustEligibility $getBotTrustEligibility,
    ) {
    }

    /**
     * @return array<string, array{0: string, 1: int}>
     */
    public static function getSubscribedEvents(): array
    {
        return [
            // Priority 10: same slot as MercureSubscribeCookieListener — before
            // Symfony's SetCookieSubscriber (0) and long before the cache-header
            // subscriber (-900) inspects the response.
            KernelEvents::RESPONSE => ['onKernelResponse', 10],
            // After Symfony's DefaultLogoutListener (64) has set the redirect response
            LogoutEvent::class => ['onLogout', -64],
        ];
    }

    public function onKernelResponse(ResponseEvent $event): void
    {
        if (!$event->isMainRequest() || !$this->signer->isEnabled()) {
            return;
        }

        $user = $this->tokenStorage->getToken()?->getUser();

        if (!$user instanceof UserAccount) {
            return;
        }

        $request = $event->getRequest();
        $uid = BotTrustCookieSigner::uidFor($user->getUserIdentifier());
        // Real time, never the app's clock: the issued-at and the expiry are judged by the sidecar and the browser on
        // their own clocks (docs/features/time-and-clock)
        // @phpstan-ignore clock.systemTime (the bot-blocker sidecar validates the issued-at on its own clock, the browser its expiry)
        $now = new DateTimeImmutable();
        $nowMs = (int) $now->format('Uv');

        $existing = $request->cookies->get(BotTrustCookieSigner::COOKIE_NAME);

        if (is_string($existing)) {
            $issuedAtMs = $this->signer->parseIssuedAt($existing, $uid);

            // Valid, belongs to this account and still fresh — nothing to do, no query.
            if ($issuedAtMs !== null && !BotTrustCookieSigner::needsRefresh($issuedAtMs, $nowMs)) {
                return;
            }
        }

        if ($this->getBotTrustEligibility->isEligible($user->getUserIdentifier())) {
            $event->getResponse()->headers->setCookie(
                Cookie::create(BotTrustCookieSigner::COOKIE_NAME)
                    ->withValue($this->signer->build($uid))
                    ->withExpires($now->modify('+' . BotTrustCookieSigner::LIFETIME_DAYS . ' days'))
                    ->withPath('/')
                    ->withSecure($request->isSecure())
                    ->withHttpOnly(true)
                    ->withSameSite('lax'),
            );

            return;
        }

        if ($existing !== null) {
            self::expireCookie($request, $event->getResponse());
        }
    }

    /**
     * Signing out ends the browser's trust - whether or not the feature is enabled, and whatever the request carried
     * (expiring a cookie the browser does not have is harmless).
     */
    public function onLogout(LogoutEvent $event): void
    {
        $response = $event->getResponse();

        if ($response === null) {
            return;
        }

        self::expireCookie($event->getRequest(), $response);
    }

    /**
     * Same name, path, secure, httponly and samesite as the minted cookie, with an expiry in the past (1970).
     */
    private static function expireCookie(Request $request, Response $response): void
    {
        $response->headers->clearCookie(
            BotTrustCookieSigner::COOKIE_NAME,
            path: '/',
            secure: $request->isSecure(),
            httpOnly: true,
            sameSite: Cookie::SAMESITE_LAX,
        );
    }
}
