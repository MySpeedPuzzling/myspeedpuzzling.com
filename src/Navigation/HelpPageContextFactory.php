<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Navigation;

use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Value\ReturnUrl;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Exception\ExceptionInterface as RoutingException;
use Symfony\Component\Routing\Route;
use Symfony\Component\Routing\RouterInterface;

/**
 * Reads the Help sheet's ?url= (implementation plan §7, HELP-01a). The parameter is untrusted: only a same-site
 * relative path (ReturnUrl) or an absolute URL on the request's own host (old feedback links carried app.request.uri)
 * is accepted, and only when the router matches it - anything else is the empty context. Plain value logic, no state.
 *
 * Private links (HELP review Q2): a page whose URL holds a private token is never attached by its URL - not by the
 * openers (help_path()), not in the box, not in the e-mail. Its token-free path stands in for it ("/delete-account/{token}":
 * the route's path, every variable left as its placeholder, the query dropped), recognised again when it comes back.
 */
final readonly class HelpPageContextFactory
{
    /**
     * Every route whose URL carries a private token - a token in the path, a signed query (UriSigner) or a secret query
     * parameter. Canonical names (a localized route's every locale). HelpPrivateLinkRoutesTest fails for a token route
     * missing here.
     */
    public const array PRIVATE_LINK_ROUTES = [
        // A token in the path
        'claim_oauth2_credentials',
        // A participant's personal link of an online round (bar-free: no opener on it, listed like every token route)
        'compete_connect',
        'compete_page',
        'confirm_account_deletion',
        'email_preferences',
        'email_preferences_save',
        'newsletter_confirm',
        'newsletter_unsubscribe',
        'newsletter_unsubscribe_confirm',
        'newsletter_unsubscribed',
        'participant_import_confirm',
        'participant_import_discard',
        'participant_import_preview',
        'password_reset',
        'photo_stash_preview',
        'social_link_finish',
        // A signed query (UriSigner): whoever has the link acts for the player
        'content_digest_unsubscribe',
        'digest_emails_unsubscribe',
        'result_emails_unsubscribe',
        // A secret in the query
        'claim_voucher',
        'sign_in_link_check',
        'social_login_callback',
        'social_register_confirm',
        'verify_email',
    ];

    /** A localized route has one route per locale (name.en, …) */
    private const array LOCALES = ['cs', 'en', 'es', 'ja', 'fr', 'de'];

    public function __construct(
        private RouterInterface $router,
    ) {
    }

    public function fromUrlParameter(null|string $url, Request $request): HelpPageContext
    {
        $path = $this->sameSitePath($url, $request);

        if ($path === null) {
            return HelpPageContext::empty();
        }

        $pathname = (string) parse_url('http://host' . $path, PHP_URL_PATH);

        // A private link (its URL, or its token-free path coming back from an opener): never kept by its URL
        $privateLinkPath = $this->tokenFreePathOf($pathname);

        if ($privateLinkPath !== null) {
            return HelpPageContext::privateLink($privateLinkPath);
        }

        // A page is something one GETs: matched as a GET even from the feedback box's POST (HELP-01b) - the router's
        // context carries the current request's method, and GET-only routes (e.g. `menu`) refuse a POST
        $routerContext = $this->router->getContext();
        $method = $routerContext->getMethod();
        $routerContext->setMethod('GET');

        try {
            // match() decodes the path itself (a raw or a percent-encoded ja path match the same route)
            $attributes = $this->router->match($pathname !== '' ? $pathname : '/');
        } catch (RoutingException) {
            return HelpPageContext::empty();
        } finally {
            $routerContext->setMethod($method);
        }

        $route = $attributes['_canonical_route'] ?? $attributes['_route'] ?? null;

        if (!is_string($route)) {
            return HelpPageContext::empty();
        }

        // Only the route's own path variables: a path differing by its trailing slash or scheme matches through
        // Symfony's RedirectController, whose attributes (path, permanent, scheme, ports) are not route parameters
        $params = [];

        foreach ($this->pathVariables($attributes) as $name) {
            if (array_key_exists($name, $attributes) && !str_starts_with($name, '_')) {
                $params[$name] = $attributes[$name];
            }
        }

        $puzzleId = $params['puzzleId'] ?? null;

        return new HelpPageContext(
            path: $path,
            routeName: $route,
            routeParams: $params,
            puzzleId: $route === 'puzzle_detail' && is_string($puzzleId) && Uuid::isValid($puzzleId) ? $puzzleId : null,
        );
    }

    /**
     * help_path() on a private link page (HELP review Q2): the token-free path the opener attaches instead of the URL,
     * null on any other page
     */
    public function privateLinkPathOfRequest(Request $request): null|string
    {
        $canonical = $request->attributes->get('_canonical_route') ?? $request->attributes->get('_route');
        $name = $request->attributes->get('_route');

        if (!in_array($canonical, self::PRIVATE_LINK_ROUTES, true) || !is_string($name)) {
            return null;
        }

        // The matched name of a localized route is its canonical one, the collection holds name.<locale>
        $routes = $this->router->getRouteCollection();
        $route = $routes->get($name) ?? $routes->get($name . '.' . $request->getLocale());

        return $route !== null ? self::tokenFreePath($route, $request->getLocale()) : null;
    }

    /**
     * The token-free path of a private link page, given its URL's path (any method: a form page re-rendered at its
     * POST URL counts too) or its token-free path itself; null for any other path
     */
    private function tokenFreePathOf(string $pathname): null|string
    {
        $decoded = rawurldecode($pathname);
        $routes = $this->router->getRouteCollection();

        foreach (self::PRIVATE_LINK_ROUTES as $name) {
            foreach ($this->variantsOf($name) as $variant) {
                $route = $routes->get($variant);

                if ($route === null) {
                    continue;
                }

                if (preg_match($route->compile()->getRegex(), $decoded, $matches) === 1) {
                    $locale = $matches['_locale'] ?? $route->getDefault('_locale');

                    return self::tokenFreePath($route, is_string($locale) ? $locale : '');
                }

                foreach (self::localesOf($route) as $locale) {
                    if ($decoded === self::tokenFreePath($route, $locale)) {
                        return $decoded;
                    }
                }
            }
        }

        return null;
    }

    /**
     * The route itself, or a localized route's one route per locale (name.en, …)
     *
     * @return list<string>
     */
    private function variantsOf(string $name): array
    {
        $routes = $this->router->getRouteCollection();

        if ($routes->get($name) !== null) {
            return [$name];
        }

        $variants = [];

        foreach (self::LOCALES as $locale) {
            $variants[] = $name . '.' . $locale;
        }

        return $variants;
    }

    /**
     * The locales a {_locale} in the route's path stands for (none without one)
     *
     * @return list<string>
     */
    private static function localesOf(Route $route): array
    {
        if (!str_contains($route->getPath(), '{_locale}')) {
            return [''];
        }

        $requirement = $route->getRequirement('_locale');

        return $requirement !== null ? explode('|', $requirement) : self::LOCALES;
    }

    /**
     * The route's path with its locale filled in and every other variable left as its {placeholder}
     */
    private static function tokenFreePath(Route $route, string $locale): string
    {
        return $locale !== '' ? str_replace('{_locale}', $locale, $route->getPath()) : $route->getPath();
    }

    /**
     * The matched route's own path variables. match() names a localized route by its canonical name, while the
     * collection holds one route per locale (name.en, …), so both names are looked up.
     *
     * @param array<array-key, mixed> $attributes
     * @return list<string>
     */
    private function pathVariables(array $attributes): array
    {
        $name = $attributes['_route'] ?? null;
        $locale = $attributes['_locale'] ?? null;

        if (!is_string($name)) {
            return [];
        }

        $routes = $this->router->getRouteCollection();
        $route = $routes->get($name) ?? (is_string($locale) ? $routes->get($name . '.' . $locale) : null);

        if ($route === null) {
            return [];
        }

        return array_values(array_filter($route->compile()->getPathVariables(), is_string(...)));
    }

    /**
     * The path + query of a same-site URL, or null
     */
    private function sameSitePath(null|string $url, Request $request): null|string
    {
        if ($url === null || $url === '') {
            return null;
        }

        // A browser always percent-encodes these in a request URI (help_path() hands the page's own): raw, the value was
        // typed or forged - and the path goes into the sheet's links (the language row, Reload; HELP-02)
        if (preg_match('/[ "<>`]/', $url) === 1) {
            return null;
        }

        if (!str_starts_with($url, '/')) {
            $parts = parse_url($url);

            if (
                $parts === false
                || !in_array(strtolower($parts['scheme'] ?? ''), ['http', 'https'], true)
                || strtolower($parts['host'] ?? '') !== strtolower($request->getHost())
                || isset($parts['user'])
                || isset($parts['pass'])
            ) {
                return null;
            }

            $url = ($parts['path'] ?? '/') . (isset($parts['query']) ? '?' . $parts['query'] : '');
        }

        // Drops "//evil", "/\evil", control characters and every encoded variant of them
        $returnUrl = ReturnUrl::tryFrom($url);

        return $returnUrl?->path;
    }
}
