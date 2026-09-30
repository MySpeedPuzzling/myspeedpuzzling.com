<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\EventSubscriber;

use SpeedPuzzling\Web\Services\SocialLogin\SocialLoginSettings;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\Cookie;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\Routing\RouterInterface;

/**
 * The native auth funnel (issue #147) keeps single canonical URLs - /login stays
 * /login, the sign-in link in an email must work whatever language the reader
 * uses - so those pages carry no locale in the path. They still have to appear
 * in all six locales (D17; Auth0's Universal Login was auto-localized and
 * English-only auth pages would be a regression), so the locale is negotiated
 * here - the single place - in this order:
 *
 *  1. `?_locale=xx` - what the header language switcher produces for a route
 *     without {_locale} in its path. Remembered in the `msp_locale` cookie so the
 *     next auth page (login -> register -> sign-in link) keeps the choice.
 *  2. the `msp_locale` cookie.
 *  3. a same-origin Referer: the locale of the localized page the visitor came
 *     from, matched through the router (Czech URLs may carry no /cs prefix).
 *  4. Accept-Language, default English.
 *
 * Because the same URL then answers in six languages, these responses must never
 * be shared-cached: `no-store` also keeps the AnonymousCacheHeadersSubscriber
 * (#164) from marking a login form `public, s-maxage=60`. That is also why the
 * cookie is only ever set here, never on an anonymously cacheable page.
 *
 * Pages opt in with the `_auth_page` route default.
 */
final readonly class NativeAuthPageSubscriber implements EventSubscriberInterface
{
    public const string ROUTE_DEFAULT = '_auth_page';

    public const string LOCALE_COOKIE = 'msp_locale';

    /** @var list<string> */
    private const array SUPPORTED_LOCALES = ['en', 'cs', 'de', 'es', 'fr', 'ja'];

    private const string REMEMBER_LOCALE_ATTRIBUTE = '_auth_page_remember_locale';

    public function __construct(
        private RouterInterface $router,
    ) {
    }

    /**
     * @return array<string, array{0: string, 1: int}>
     */
    public static function getSubscribedEvents(): array
    {
        return [
            // After RouterListener (32), which sets the _auth_page attribute, and
            // before LocaleListener (16), which copies the _locale attribute into the
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

        $queryLocale = $this->supportedLocale($request->query->get('_locale'));

        if ($queryLocale !== null) {
            $request->attributes->set(self::REMEMBER_LOCALE_ATTRIBUTE, $queryLocale);
        }

        $locale = $queryLocale
            ?? $this->supportedLocale($request->cookies->get(self::LOCALE_COOKIE))
            ?? $this->refererLocale($request)
            ?? $request->getPreferredLanguage(self::SUPPORTED_LOCALES)
            ?? self::SUPPORTED_LOCALES[0];

        $request->attributes->set('_locale', $locale);
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
        $response->setVary(['Accept-Language', 'Cookie'], replace: false);

        $rememberLocale = $request->attributes->get(self::REMEMBER_LOCALE_ATTRIBUTE);

        if (is_string($rememberLocale)) {
            $response->headers->setCookie(Cookie::create(
                self::LOCALE_COOKIE,
                $rememberLocale,
                expire: time() + 365 * 24 * 60 * 60,
                path: '/',
                secure: $request->isSecure(),
                httpOnly: true,
                sameSite: Cookie::SAMESITE_LAX,
            ));
        }

        // Meta App Review preview (SocialLoginSettings): carry ?facebook_preview=1
        // to the next pages the reviewer opens. Removed with the Facebook flag.
        if ($request->query->get(SocialLoginSettings::FACEBOOK_PREVIEW_QUERY) === '1') {
            $response->headers->setCookie(Cookie::create(
                SocialLoginSettings::FACEBOOK_PREVIEW_COOKIE,
                '1',
                expire: time() + 24 * 60 * 60,
                path: '/',
                secure: $request->isSecure(),
                httpOnly: true,
                sameSite: Cookie::SAMESITE_LAX,
            ));
        }
    }

    private function supportedLocale(mixed $value): null|string
    {
        return is_string($value) && in_array($value, self::SUPPORTED_LOCALES, true) ? $value : null;
    }

    private function refererLocale(Request $request): null|string
    {
        $referer = $request->headers->get('referer');

        if ($referer === null || $referer === '') {
            return null;
        }

        $parts = parse_url($referer);

        if (
            $parts === false
            || !isset($parts['host'], $parts['scheme'])
            || !in_array(strtolower($parts['scheme']), ['http', 'https'], true)
            || strtolower($parts['host']) !== strtolower($request->getHost())
        ) {
            return null;
        }

        $path = $parts['path'] ?? '/';
        $baseUrl = $request->getBaseUrl();

        if ($baseUrl !== '' && str_starts_with($path, $baseUrl)) {
            $path = substr($path, strlen($baseUrl));
        }

        // Match the referer as the GET page it was, whatever this request's method
        // is, and hand the context back exactly as the RouterListener left it
        $context = $this->router->getContext();
        $originalMethod = $context->getMethod();
        $context->setMethod('GET');

        try {
            $parameters = $this->router->match($path === '' ? '/' : $path);
        } catch (\Throwable) {
            return null;
        } finally {
            $context->setMethod($originalMethod);
        }

        return $this->supportedLocale($parameters['_locale'] ?? null);
    }
}
