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

        try {
            // match() decodes the path itself (a raw or a percent-encoded ja path match the same route)
            $attributes = $this->router->match($pathname !== '' ? $pathname : '/');
        } catch (RoutingException) {
            return HelpPageContext::empty();
        }

        $route = $attributes['_canonical_route'] ?? $attributes['_route'] ?? null;

        if (!is_string($route)) {
            return HelpPageContext::empty();
        }

        $params = [];

        foreach ($attributes as $name => $value) {
            if (!str_starts_with((string) $name, '_')) {
                $params[(string) $name] = $value;
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
     * The path + query of a same-site URL, or null
     */
    private function sameSitePath(null|string $url, Request $request): null|string
    {
        if ($url === null || $url === '') {
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
