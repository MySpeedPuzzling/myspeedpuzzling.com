<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Navigation;

/**
 * One item of the navigation before the redesign and where it lives now (FormerNavigation, LAUNCH-02).
 */
final readonly class FormerNavigationItem
{
    /**
     * @param null|string $label the old label's translation key (old keys stay in the catalogues, decision 78); null =
     *                           the entry's own label (the admin key's English names are the role rows' labels)
     * @param string $entry the registry entry that is its new home: its Menu path is the "Now" column
     * @param list<string> $routes the routes the old item linked (the P0-02 baseline lists them per surface)
     * @param null|string $link the registry entry whose page the old name links, when not the entry's own (the guests'
     *                          Hub link → hub_guest)
     * @param null|NavVisibility $audience who finds it at its new home, when narrower than the entry's own audience (the
     *                                     Hub is Home once signed in)
     * @param list<string> $path an explicit new place, as translation keys ('☰' as is), for a place that is not a Menu
     *                           row: a tool of the top bar (Search, the logo), the guest card's Sign in and Join free
     */
    public function __construct(
        public FormerSurface $surface,
        public null|string $label,
        public string $entry,
        public array $routes,
        public null|string $link = null,
        public null|NavVisibility $audience = null,
        public array $path = [],
    ) {
    }

    public function linkEntry(): string
    {
        return $this->link ?? $this->entry;
    }
}
