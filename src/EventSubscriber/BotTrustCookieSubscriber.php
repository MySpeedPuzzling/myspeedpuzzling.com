<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\EventSubscriber;

use Psr\Clock\ClockInterface;
use SpeedPuzzling\Web\Services\BotTrustCookieSigner;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\Cookie;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\Security\Core\User\UserInterface;

/**
 * Mints the __bb_trust cookie (see BotTrustCookieSigner) on authenticated
 * responses. Listening on RESPONSE instead of LoginSuccessEvent covers every
 * path at once — form login, all social logins, magic link, remember-me
 * re-auth AND the existing long-lived sessions from before this feature
 * shipped (they never fire a login event again, but they hit this listener
 * on their next page load).
 *
 * Cache safety: the cookie is only ever added for AUTHENTICATED responses,
 * which AnonymousCacheHeadersSubscriber never marks shared-cacheable — the
 * anonymous s-maxage contract is untouched.
 */
final readonly class BotTrustCookieSubscriber implements EventSubscriberInterface
{
    public function __construct(
        private BotTrustCookieSigner $signer,
        private TokenStorageInterface $tokenStorage,
        private ClockInterface $clock,
    ) {
    }

    /**
     * @return array<string, array{0: string, 1: int}>
     */
    public static function getSubscribedEvents(): array
    {
        // Priority 10: same slot as MercureSubscribeCookieListener — before
        // Symfony's SetCookieSubscriber (0) and long before the cache-header
        // subscriber (-900) inspects the response.
        return [
            KernelEvents::RESPONSE => ['onKernelResponse', 10],
        ];
    }

    public function onKernelResponse(ResponseEvent $event): void
    {
        if (!$event->isMainRequest() || !$this->signer->isEnabled()) {
            return;
        }

        $user = $this->tokenStorage->getToken()?->getUser();

        if (!$user instanceof UserInterface) {
            return;
        }

        $request = $event->getRequest();
        $uid = BotTrustCookieSigner::uidFor($user->getUserIdentifier());
        $nowMs = (int) $this->clock->now()->format('Uv');

        $existing = $request->cookies->get(BotTrustCookieSigner::COOKIE_NAME);

        if (is_string($existing)) {
            $issuedAtMs = $this->signer->parseIssuedAt($existing, $uid);
            $refreshAfterMs = BotTrustCookieSigner::REFRESH_AFTER_DAYS * 24 * 60 * 60 * 1000;

            // Valid, belongs to this account and still fresh — nothing to do.
            if ($issuedAtMs !== null && $nowMs - $issuedAtMs < $refreshAfterMs) {
                return;
            }
        }

        $event->getResponse()->headers->setCookie(
            Cookie::create(BotTrustCookieSigner::COOKIE_NAME)
                ->withValue($this->signer->build($uid))
                ->withExpires($this->clock->now()->modify('+' . BotTrustCookieSigner::LIFETIME_DAYS . ' days'))
                ->withPath('/')
                ->withSecure($request->isSecure())
                ->withHttpOnly(true)
                ->withSameSite('lax'),
        );
    }
}
