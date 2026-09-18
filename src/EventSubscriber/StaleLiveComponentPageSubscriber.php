<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\EventSubscriber;

use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;
use SpeedPuzzling\Web\Value\ReturnUrl;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\Cookie;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Reloads a page whose live components were signed with a key or algorithm that
 * is no longer in use, instead of failing every one of its re-renders.
 *
 * Every live component embeds its props with an HMAC checksum. A re-render whose
 * checksum does not verify comes from a page rendered before the signing changed
 * - an APP_SECRET rotation (2026-07-26) or a symfony/ux-live-component release
 * that changed the checksum pre-image (2.36 / 3.1, deployed 2026-07-12) - and
 * held open ever since: a tab left open across the deploy, a tab the browser
 * restored from its HTTP cache, or (until service worker v7, 2026-09-17) a
 * document the service worker answered from its image cache. Nothing on such a
 * page can ever re-render again; every component on it carries the same stale
 * signature.
 *
 * Out of the box the library answers 400 and its Stimulus controller writes the
 * error page into a full-screen modal - on every debounced keystroke in the
 * header search, every lazy component scrolled into view, every RecentActivity
 * poll. Here the request is answered with the library's own redirect protocol
 * instead (LiveComponentSubscriber turns the redirect into 204 + X-Live-Redirect,
 * the controller then calls Turbo.visit()), pointing at the page the visitor is
 * on (the controller sends it as X-Live-Url), so the page is simply fetched fresh.
 * Being a server-side protocol, this also reaches the stale pages themselves,
 * which by definition run a JavaScript bundle from before any fix.
 *
 * Not a Sentry issue: the redirect is the complete remedy, and the caches that
 * serve old documents have their own detector (StaleDocumentController). Logged
 * at info only.
 *
 * A checksum that fails again on the page that was just reloaded is not
 * staleness but a bug - a prop that does not survive the JSON round trip through
 * the browser, web replicas disagreeing on APP_SECRET. A short-lived cookie
 * remembers the reload per page path; a repeat failure within that window is left
 * alone and surfaces as the uncaught 400 it always was (logged as an error, so in
 * Sentry), which also rules out a reload loop.
 */
final readonly class StaleLiveComponentPageSubscriber implements EventSubscriberInterface
{
    public const string RELOAD_COOKIE = 'live_component_reload';

    public const int RELOAD_GUARD_SECONDS = 600;

    /**
     * Messages of the HydrationException LiveComponentHydrator::verifyChecksum()
     * throws on a mismatch, for the component's own props and for props handed down
     * by a parent component. Matched on the public parent class plus the exact
     * message because HydrationException itself is @internal.
     * StaleLiveComponentPageSubscriberTest provokes both through the real hydrator,
     * so a library release that rewords them fails the suite instead of silently
     * switching this off.
     */
    private const array CHECKSUM_MISMATCH_MESSAGES = [
        'Invalid checksum sent when updating the live component.',
        'Invalid checksum for the data sent from the parent component.',
    ];

    private const string LIVE_COMPONENT_CONTENT_TYPE = 'application/vnd.live-component+html';

    public function __construct(
        private LoggerInterface $logger,
        private ClockInterface $clock,
    ) {
    }

    /**
     * @return array<string, array{0: string, 1: int}>
     */
    public static function getSubscribedEvents(): array
    {
        // Above HttpKernel's ErrorListener::logKernelException (0), which would log
        // the exception as an uncaught error. Setting a response stops propagation.
        return [
            KernelEvents::EXCEPTION => ['onKernelException', 30],
        ];
    }

    public function onKernelException(ExceptionEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $exception = $event->getThrowable();

        if (
            !$exception instanceof BadRequestHttpException
            || !in_array($exception->getMessage(), self::CHECKSUM_MISMATCH_MESSAGES, true)
        ) {
            return;
        }

        $request = $event->getRequest();

        if (!self::isSentByLiveController($request)) {
            return;
        }

        $pageUrl = ReturnUrl::tryFrom($request->headers->get('X-Live-Url'));

        if ($pageUrl === null) {
            return;
        }

        $pagePath = explode('?', $pageUrl->path, 2)[0];

        if ($request->cookies->get(self::RELOAD_COOKIE) === $pagePath) {
            return;
        }

        $this->logger->info('Live component re-render carried a stale checksum, reloading the page', [
            'component' => $request->attributes->get('_live_component'),
            'action' => $request->attributes->get('_live_action'),
            'page' => $pageUrl->path,
            'exception' => $exception,
        ]);

        $response = new RedirectResponse($pageUrl->path);
        $response->headers->setCookie(
            Cookie::create(self::RELOAD_COOKIE)
                ->withValue($pagePath)
                ->withExpires($this->clock->now()->modify('+' . self::RELOAD_GUARD_SECONDS . ' seconds'))
                ->withPath('/')
                ->withSecure($request->isSecure())
                ->withHttpOnly(true)
                ->withSameSite('lax'),
        );

        $event->setResponse($response);
    }

    /**
     * Mirrors LiveComponentSubscriber::isLiveComponentRequest() outside test mode:
     * only the Stimulus controller, which understands X-Live-Redirect, gets the
     * redirect. Anything else keeps the plain 400.
     */
    private static function isSentByLiveController(Request $request): bool
    {
        return $request->attributes->has('_live_component')
            && $request->headers->get('X-Requested-With') === 'XMLHttpRequest'
            && in_array(self::LIVE_COMPONENT_CONTENT_TYPE, $request->getAcceptableContentTypes(), true);
    }
}
