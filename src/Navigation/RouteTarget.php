<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Navigation;

/**
 * Where an entry links: a route, its fixed parameters, the parameters filled per viewer or request, an optional
 * fragment and the route guests get instead. The Language row links the current page (no route of its own).
 */
final readonly class RouteTarget
{
    /**
     * @param array<string, string|int> $params
     * @param array<string, RouteParam> $dynamicParams
     */
    private function __construct(
        public null|string $route,
        public array $params = [],
        public array $dynamicParams = [],
        public null|string $fragment = null,
        public null|string $guestRoute = null,
    ) {
    }

    /**
     * @param array<string, string|int> $params
     * @param array<string, RouteParam> $dynamic
     */
    public static function route(string $name, array $params = [], array $dynamic = [], null|string $guestRoute = null): self
    {
        return new self($name, $params, $dynamic, null, $guestRoute);
    }

    /**
     * A page of the viewer's own: its playerId is the viewer's player id.
     */
    public static function viewer(string $name): self
    {
        return new self($name, [], ['playerId' => RouteParam::ViewerPlayerId]);
    }

    /**
     * A section of a page, reached by its anchor id.
     *
     * @param array<string, string|int> $params
     */
    public static function anchor(string $name, string $fragment, array $params = []): self
    {
        return new self($name, $params, [], $fragment);
    }

    /**
     * The current page in another locale (the Language row).
     */
    public static function currentPage(): self
    {
        return new self(null);
    }

    public function isCurrentPage(): bool
    {
        return $this->route === null;
    }

    public function needsViewer(): bool
    {
        return in_array(RouteParam::ViewerPlayerId, $this->dynamicParams, true)
            || in_array(RouteParam::ViewerCountryCode, $this->dynamicParams, true);
    }

    /**
     * The route and the guests' route.
     *
     * @return list<string>
     */
    public function routes(): array
    {
        $routes = [];

        if ($this->route !== null) {
            $routes[] = $this->route;
        }

        // A guests' route may be the same page (What next?: its public landing) - listed once
        if ($this->guestRoute !== null && $this->guestRoute !== $this->route) {
            $routes[] = $this->guestRoute;
        }

        return $routes;
    }

    /**
     * Human-readable form for the generated mirror, e.g. "player_statistics {playerId: viewer}" or "edit_profile#emails".
     */
    public function describe(): string
    {
        if ($this->route === null) {
            return 'the current page in another language';
        }

        $parts = [];

        foreach ($this->params as $name => $value) {
            $parts[] = $name . ': ' . $value;
        }

        foreach ($this->dynamicParams as $name => $source) {
            $parts[] = $name . ': ' . match ($source) {
                RouteParam::ViewerPlayerId => 'viewer',
                RouteParam::ViewerCountryCode => "viewer's country",
                RouteParam::CurrentYear => 'current year',
            };
        }

        $description = '`' . $this->route . ($this->fragment !== null ? '#' . $this->fragment : '') . '`';

        if ($parts !== []) {
            $description .= ' {' . implode(', ', $parts) . '}';
        }

        if ($this->guestRoute !== null && $this->guestRoute === $this->route) {
            $description .= ' (guests: the same page)';
        } elseif ($this->guestRoute !== null) {
            $description .= ' (guests: `' . $this->guestRoute . '`)';
        }

        return $description;
    }
}
