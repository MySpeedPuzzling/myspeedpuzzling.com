<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Navigation;

/**
 * Maps a route (+ its parameters) to where the page sits in the navigation (F-02): the place for the bars' active
 * tab, the section for the chips, the Menu row for aria-current, and whether it is a start page. Your own library and
 * its lists belong to Puzzles, your other own player pages to Profile, everybody else's player pages to Community
 * (navigation README §3). No state: the per-request memo lives on the request (NavigationTwigExtension).
 */
final readonly class NavigationPlaceResolver
{
    public function __construct(
        private NavigationRegistry $registry,
    ) {
    }

    /**
     * @param array<mixed> $params the route parameters
     */
    public function resolve(null|string $route, array $params, null|string $viewerPlayerId): NavLocation
    {
        if ($route === null) {
            return NavLocation::none();
        }

        $rule = NavigationPlaceMap::ruleFor($route);

        if ($rule === null) {
            return NavLocation::none();
        }

        $isOwner = self::isViewersPage($params, $viewerPlayerId);
        $rule = $rule->forOwner($isOwner);

        return new NavLocation(
            place: $rule->place,
            section: $rule->section,
            menuRowId: $this->menuRowId($route, $params, $viewerPlayerId),
            isStartPage: $rule->isStartPage,
        );
    }

    /**
     * The Menu row that is the current one: an entry targeting the route (a page of the viewer's own only when the
     * URL's player is the viewer) or listing it in activeOn. Link rows win over place rows, place rows over cards;
     * search-only and not-in-Menu entries are never highlighted.
     *
     * @param array<mixed> $params
     */
    private function menuRowId(string $route, array $params, null|string $viewerPlayerId): null|string
    {
        $best = null;
        $bestRank = PHP_INT_MAX;

        foreach ($this->registry->byRoute($route) as $entry) {
            if (!$entry->isMenuRow()) {
                continue;
            }

            if ($entry->target->route === $route && $entry->target->needsViewer() && !self::isViewersPage($params, $viewerPlayerId)) {
                continue;
            }

            $rank = match ($entry->kind) {
                RowKind::Place => 1,
                RowKind::YouCard, RowKind::GuestCard => 2,
                default => 0,
            };

            if ($rank < $bestRank) {
                $best = $entry->id;
                $bestRank = $rank;
            }
        }

        return $best;
    }

    /**
     * @param array<mixed> $params
     */
    private static function isViewersPage(array $params, null|string $viewerPlayerId): bool
    {
        $playerId = $params['playerId'] ?? null;

        return $viewerPlayerId !== null
            && is_string($playerId)
            && strtolower($playerId) === strtolower($viewerPlayerId);
    }
}
