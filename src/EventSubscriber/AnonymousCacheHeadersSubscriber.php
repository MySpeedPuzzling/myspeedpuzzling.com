<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\EventSubscriber;

use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\EventListener\AbstractSessionListener;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Marks anonymous HTML responses as shared-cacheable (public, s-maxage) so an
 * edge cache (Cloudflare) can serve them without hitting the origin. Browsers
 * keep revalidating on every navigation (max-age=0), so nothing changes for
 * users until a CDN actually caches HTML.
 *
 * A response qualifies only when nothing about it can be visitor-specific:
 * the request carried no session cookie, no session was started while
 * handling it, and no cookie is being sent back.
 */
final class AnonymousCacheHeadersSubscriber implements EventSubscriberInterface
{
    public const int SHARED_MAX_AGE = 60;

    /**
     * @return array<string, array{0: string, 1: int}>
     */
    public static function getSubscribedEvents(): array
    {
        // After all cookie-setting subscribers (priority >= 0), but before
        // AbstractSessionListener (-1000): the NO_AUTO_CACHE_CONTROL marker is
        // still present here, and a session started during the request has not
        // yet attached its cookie - we check isStarted() for that instead.
        return [
            KernelEvents::RESPONSE => ['onKernelResponse', -900],
        ];
    }

    public function onKernelResponse(ResponseEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $request = $event->getRequest();
        $response = $event->getResponse();

        if (
            !$request->isMethodCacheable()
            || $response->getStatusCode() !== 200
            // Responses to authorized requests must never become publicly cacheable
            // (`public` explicitly permits shared caches to store them, RFC 9111).
            || $request->headers->has('Authorization')
            // Turbo Frame fragments share the URL with the full page - caching
            // either under that URL would serve the wrong variant to the other.
            || $request->headers->has('Turbo-Frame')
        ) {
            return;
        }

        // Visitor arrived with a session cookie: response may be personalized.
        if ($request->hasPreviousSession()) {
            return;
        }

        // A session was started while handling the request (login, CSRF token,
        // flash message): the session listener will attach the cookie and stamp
        // the response private after us - do not fight it.
        if ($request->hasSession() && $request->getSession()->isStarted()) {
            return;
        }

        if ($response->headers->getCookies() !== []) {
            return;
        }

        // The route manages its own caching (sitemap, homepage stats, ...).
        if ($response->headers->has(AbstractSessionListener::NO_AUTO_CACHE_CONTROL_HEADER)) {
            return;
        }

        // Explicit cache-control was set by the controller - the default
        // computed value is only ever "no-cache, private", so any of these
        // directives means somebody made a deliberate choice.
        if (
            $response->headers->hasCacheControlDirective('public')
            || $response->headers->hasCacheControlDirective('s-maxage')
            || $response->headers->hasCacheControlDirective('no-store')
            || $response->headers->hasCacheControlDirective('max-age')
        ) {
            return;
        }

        // Default HTML responses have no Content-Type yet at this point (it is
        // filled in Response::prepare()); anything else declares its own.
        $contentType = (string) $response->headers->get('Content-Type');

        if ($contentType !== '' && !str_starts_with($contentType, 'text/html')) {
            return;
        }

        $response->setPublic();
        $response->setMaxAge(0);
        $response->setSharedMaxAge(self::SHARED_MAX_AGE);

        // Checking auth state (the logged_user Twig global does, on every page)
        // bumps the session usage index via UsageTrackingTokenStorage, which
        // makes AbstractSessionListener re-stamp the response private even
        // though no session exists. For a cookie-less request the auth state is
        // deterministically anonymous, so opt out of that auto stamp. The
        // listener removes this internal header before the response is sent.
        $response->headers->set(AbstractSessionListener::NO_AUTO_CACHE_CONTROL_HEADER, 'true');
    }
}
