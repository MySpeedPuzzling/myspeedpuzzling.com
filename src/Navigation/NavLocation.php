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
}
