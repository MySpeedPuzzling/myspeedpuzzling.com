<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\EventSubscriber;

use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * The native auth funnel (issue #147) keeps single canonical URLs - /login stays
 * /login, the sign-in link in an email must work whatever language the reader
 * uses - so those pages carry no locale in the path. They still have to appear
 * in all six locales (D17; Auth0's Universal Login was auto-localized and
 * English-only auth pages would be a regression), so the locale is negotiated
 * from the browser instead of the URL.
 *
 * Because the same URL then answers in six languages, these responses must never
 * be shared-cached: `no-store` also keeps the AnonymousCacheHeadersSubscriber
 * (#164) from marking a login form `public, s-maxage=60`.
 *
 * Pages opt in with the `_auth_page` route default.
 */
final class NativeAuthPageSubscriber implements EventSubscriberInterface
{
    public const string ROUTE_DEFAULT = '_auth_page';

    /** @var list<string> */
    private const array SUPPORTED_LOCALES = ['en', 'cs', 'de', 'es', 'fr', 'ja'];

    /**
     * @return array<string, array{0: string, 1: int}>
     */
    public static function getSubscribedEvents(): array
    {
        return [
            // Before LocaleListener (16), which copies the _locale attribute into the
            // request, the translator and the router context (so menu links and
            // path() calls in the rendered page follow the negotiated locale)
            KernelEvents::REQUEST => ['onKernelRequest', 20],
            // Before AnonymousCacheHeadersSubscriber (-900), which honours no-store
            KernelEvents::RESPONSE => ['onKernelResponse', 0],
        ];
    }

    public function onKernelRequest(RequestEvent $event): void
    {
        $request = $event->getRequest();

        if (!$event->isMainRequest() || $request->attributes->get(self::ROUTE_DEFAULT) !== true) {
            return;
        }

        $preferredLanguage = $request->getPreferredLanguage(self::SUPPORTED_LOCALES);

        $request->attributes->set('_locale', $preferredLanguage ?? self::SUPPORTED_LOCALES[0]);
    }

    public function onKernelResponse(ResponseEvent $event): void
    {
        $request = $event->getRequest();

        if (!$event->isMainRequest() || $request->attributes->get(self::ROUTE_DEFAULT) !== true) {
            return;
        }

        $response = $event->getResponse();

        $response->setPrivate();
        $response->headers->addCacheControlDirective('no-store');
        $response->setVary('Accept-Language', replace: false);
    }
}
