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
    /**
     * Request attribute: the player whose page this is, set by a controller whose URL carries no playerId (e.g.
     * collection_detail) - so somebody else's page goes to Community, never to your own Puzzles › My library.
     */
    public const string PAGE_OWNER_ATTRIBUTE = '_nav_page_owner_player_id';

    public function __construct(
        private NavigationRegistry $registry,
    ) {
    }

    /**
     * @param array<mixed> $params the route parameters
     * @param null|string $pageOwnerPlayerId whose page it is when the URL has no playerId (PAGE_OWNER_ATTRIBUTE)
     */
    public function resolve(null|string $route, array $params, null|string $viewerPlayerId, null|string $pageOwnerPlayerId = null): NavLocation
    {
        if ($route === null) {
            return NavLocation::none();
        }

        $rule = NavigationPlaceMap::ruleFor($route);

        if ($rule === null) {
            return NavLocation::none();
        }

        $isViewersPage = self::isViewersPage(self::owner($params, $pageOwnerPlayerId), $viewerPlayerId);
        $placeRule = $rule->forOwner($isViewersPage);

        return new NavLocation(
            place: $placeRule->place,
            section: $placeRule->section,
            menuRowId: $this->menuRowId($route, $rule->isOwnOrOther(), $isViewersPage),
            isStartPage: $placeRule->isStartPage,
        );
    }

    /**
     * The Menu row that is the current one: an entry targeting the route or listing it in activeOn. A row of the
     * viewer's own (its target needs the viewer) is current only on the viewer's page - on its own target route, and
     * on an activeOn route whose place depends on the owner (somebody else's collection is not "your" Collection
     * row). Link rows win over place rows, place rows over cards; search-only and not-in-Menu entries are never
     * highlighted.
     */
    private function menuRowId(string $route, bool $ownerDecides, bool $isViewersPage): null|string
    {
        $best = null;
        $bestRank = PHP_INT_MAX;

        foreach ($this->registry->byRoute($route) as $entry) {
            if (!$entry->isMenuRow()) {
                continue;
            }

            if ($entry->target->needsViewer() && !$isViewersPage && ($entry->target->route === $route || $ownerDecides)) {
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
     * The page's player: the URL's playerId, else the one its controller named.
     *
     * @param array<mixed> $params
     */
    private static function owner(array $params, null|string $pageOwnerPlayerId): null|string
    {
        $playerId = $params['playerId'] ?? null;

        return is_string($playerId) && $playerId !== '' ? $playerId : $pageOwnerPlayerId;
    }

    private static function isViewersPage(null|string $ownerPlayerId, null|string $viewerPlayerId): bool
    {
        return $viewerPlayerId !== null
            && $ownerPlayerId !== null
            && strtolower($ownerPlayerId) === strtolower($viewerPlayerId);
    }
}
