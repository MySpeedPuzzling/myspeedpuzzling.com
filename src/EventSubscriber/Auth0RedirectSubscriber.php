<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\EventSubscriber;

use SpeedPuzzling\Web\Security\Auth0EntryPoint;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\KernelEvents;

final class Auth0RedirectSubscriber implements EventSubscriberInterface
{
    /**
     * @return array<string, array{0: string, 1: int}>
     */
    public static function getSubscribedEvents(): array
    {
        return [
            KernelEvents::RESPONSE => ['onKernelResponse', 0],
        ];
    }

    public function onKernelResponse(ResponseEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $request = $event->getRequest();
        $response = $event->getResponse();
        $path = $request->getPathInfo();

        // Only process redirects from Auth0 callback OR login
        if ($path !== '/auth/callback' && $path !== '/login') {
            return;
        }

        if (!$response instanceof RedirectResponse) {
            return;
        }

        $redirectCookie = $request->cookies->get(Auth0EntryPoint::REDIRECT_COOKIE);

        if ($redirectCookie === null || $redirectCookie === '') {
            $this->rememberRedirectTargetFromReferer($request, $response, $path);

            return;
        }

        // For /login: only intercept local redirects (not Auth0 redirects)
        if ($path === '/login') {
            $targetUrl = $response->getTargetUrl();
            $host = $request->getSchemeAndHttpHost();

            // Skip if redirecting to Auth0 (external URL)
            if (!str_starts_with($targetUrl, $host)) {
                return;
            }
        }

        // Clear the cookie and redirect to the original target
        $newResponse = new RedirectResponse($redirectCookie);
        $newResponse->headers->clearCookie(
            Auth0EntryPoint::REDIRECT_COOKIE,
            '/',
        );

        $event->setResponse($newResponse);
    }

    /**
     * A direct "Sign in" click goes straight to /login without passing through
     * Auth0EntryPoint, so no redirect cookie exists yet. Remember the page the
     * user came from (same-origin Referer) so the callback can send them back.
     * Cookie, not session - anonymous requests must never start a session.
     */
    private function rememberRedirectTargetFromReferer(Request $request, RedirectResponse $response, string $path): void
    {
        if ($path !== '/login') {
            return;
        }

        $host = $request->getSchemeAndHttpHost();

        // Only when leaving for Auth0 (login actually starting), not on local redirects
        if (str_starts_with($response->getTargetUrl(), $host)) {
            return;
        }

        $referer = $request->headers->get('referer');

        if (
            !is_string($referer)
            || !str_starts_with($referer, $host . '/')
            || str_starts_with($referer, $host . '/login')
        ) {
            return;
        }

        $response->headers->setCookie(
            Auth0EntryPoint::createRedirectCookie($referer, $request->isSecure()),
        );
    }
}
