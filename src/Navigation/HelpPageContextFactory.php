<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Navigation;

use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Value\ReturnUrl;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Exception\ExceptionInterface as RoutingException;
use Symfony\Component\Routing\RouterInterface;

/**
 * Reads the Help sheet's ?url= (implementation plan §7, HELP-01a). The parameter is untrusted: only a same-site
 * relative path (ReturnUrl) or an absolute URL on the request's own host (old feedback links carried app.request.uri)
 * is accepted, and only when the router matches it - anything else is the empty context. Plain value logic, no state.
 */
final readonly class HelpPageContextFactory
{
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
