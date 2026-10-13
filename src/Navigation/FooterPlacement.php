<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Navigation;

/**
 * An entry's place in the site footer (FOOT-01, README §4.11): the footer renders its columns from these placements
 * (NavigationRegistry::footer(), FooterBuilder).
 *
 * The footer is an index for everybody - "the same columns for everyone" (docs/features/site-footer.md) - so its
 * audience is its own, not the Menu row's: a page that needs signing in stays linked for guests as it always was
 * (README §4.11 "every route linked from today's footer stays linked"); only the XP pages (the XP gate) and "Where did
 * it go?" (signed-in players, R2) narrow it.
 */
final readonly class FooterPlacement
{
    public function __construct(
        public FooterColumn $column,
        public int $position,
        // "New" badge, shown while NavigationLaunch::newBadgesVisible() (decision 1)
        public bool $newBadge = false,
        // The data-nav "<place>/<item>" this link carried before FOOT-01 (P0-03, collected on production since then):
        // kept so its clicks stay comparable across the launch; null = a link new in FOOT-01, "<column>/<entry id>"
        public null|string $nav = null,
        // rel="nofollow" where the footer had it (pages behind sign-in)
        public bool $nofollow = false,
        public NavVisibility $visibility = NavVisibility::Everyone,
    ) {
    }

    /**
     * The link's data-nav value without the surface: "<place>/<item>".
     */
    public function navFor(string $entryId): string
    {
        return $this->nav ?? $this->column->value . '/' . $entryId;
    }
}
