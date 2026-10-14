<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Navigation;

use ReflectionClass;
use Symfony\Component\Routing\RouterInterface;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Does following an entry's own link ask a guest to sign in first (SRCH+FOOT review F1)? A page of the viewer's own
 * (nav_go: sign-in, then the page), or a route whose controller wants a signed-in visitor (#[IsGranted] on its class or
 * action: the firewall sends a guest to sign in - LoginEntryPoint). A page that opens for guests never does (What
 * next?'s landing, Where did it go?, the Help page). One rule for the page search's `s` mark (NavigationSearchIndex)
 * and the "after signing in" mark of Where did it go? (WhereDidItGoBuilder, LAUNCH review F4).
 *
 * Plain service, no state between requests (FrankenPHP worker mode).
 */
final readonly class NavigationSignInRule
{
    public function __construct(
        private RouterInterface $router,
    ) {
    }

    public function asksGuestsToSignIn(NavEntry $entry): bool
    {
        if ($entry->target->needsViewer()) {
            return true;
        }

        $route = $entry->target->route;

        return $route !== null && $this->routeWantsASignedInVisitor($route);
    }

    private function routeWantsASignedInVisitor(string $route): bool
    {
        $routes = $this->router->getRouteCollection();
        // A localized route is name.<locale> in the collection
        $definition = $routes->get($route) ?? $routes->get($route . '.en');
        $controller = $definition?->getDefault('_controller');

        if (!is_string($controller)) {
            return false;
        }

        [$class, $method] = str_contains($controller, '::') ? explode('::', $controller, 2) : [$controller, '__invoke'];

        if (!class_exists($class)) {
            return false;
        }

        $reflection = new ReflectionClass($class);

        if ($reflection->getAttributes(IsGranted::class) !== []) {
            return true;
        }

        return $reflection->hasMethod($method) && $reflection->getMethod($method)->getAttributes(IsGranted::class) !== [];
    }
}
