<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Navigation;

/**
 * An entry's place in the site footer (filled when the footer is rendered from the registry, FOOT-01).
 */
final readonly class FooterPlacement
{
    public function __construct(
        public string $column,
        public int $position,
        // "New" badge, shown while NavigationLaunch::newBadgesVisible() (decision 1)
        public bool $newBadge = false,
    ) {
    }
}
