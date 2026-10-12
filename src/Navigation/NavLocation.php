<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Navigation;

/**
 * Where a page sits in the navigation: its place (the bars' active tab), its section (the chips), the Menu row to
 * highlight (aria-current) and whether it is a place's or a section's start page (no back button, GEST-03).
 */
final readonly class NavLocation
{
    public function __construct(
        public NavPlace $place,
        public null|string $section = null,
        public null|string $menuRowId = null,
        public bool $isStartPage = false,
    ) {
    }

    public static function none(): self
    {
        return new self(NavPlace::None);
    }

    /**
     * A place's own start page (README §4.1 "root pages"): Home (the Hub / the homepage), the Puzzles sections - the
     * Puzzles tab opens the last used one (D6, PUZ-01) - Add, the Community landing and your own profile. The installed
     * app's top bar shows no back button there (NAV-03). Community's sections (leaderboards, puzzlers, events) start
     * their chips, not the place: reached from the landing, they keep the back button.
     */
    public function isRootPage(): bool
    {
        return $this->isStartPage && ($this->section === null || $this->place === NavPlace::Puzzles);
    }
}
