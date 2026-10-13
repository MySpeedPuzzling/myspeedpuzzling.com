<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Navigation;

use SpeedPuzzling\Web\Results\PlayerProfile;

/**
 * One of the five places as a bar shows it (the phone tab bar NAV-01; the rail NAV-13 and the compact desktop bar
 * NAV-04 reuse it): where it leads, its label key and icon from the registry's place entry, whether it is the current
 * place - "page" on its own page, "true" deeper inside the place (decision 27) - the viewer whose avatar the Profile
 * place shows (null: guests get the person icon) and whether the link is rel="nofollow" (a page of the viewer's own,
 * as the Menu rows have it, and the guests' sign-in link - no per-page crawl variants).
 * railLabel: the label the desktop rail shows instead (NAV-13, README §4.3: the guests' Profile reads "Sign in" there) -
 * the phone tab bar and the rail are one element (#bottom-nav, R9), so both labels are rendered and CSS shows one.
 */
final readonly class PlaceLink
{
    public function __construct(
        public NavPlace $place,
        public string $href,
        public string $label,
        public string $icon,
        public null|string $current = null,
        public null|PlayerProfile $avatar = null,
        public bool $nofollow = false,
        public null|string $railLabel = null,
    ) {
    }

    /**
     * The sprite symbol of the icon (templates/navigation/_icons.html.twig): the registry's Bootstrap Icons name
     * without its prefix, e.g. bi-house-door → nav-i-house-door
     */
    public function symbol(): string
    {
        return 'nav-i-' . preg_replace('~^bi-~', '', $this->icon);
    }

    /**
     * The filled twin shown while the place is current (the Add tile has none: it is never selected)
     */
    public function filledSymbol(): null|string
    {
        return $this->place === NavPlace::Add ? null : $this->symbol() . '-fill';
    }
}
