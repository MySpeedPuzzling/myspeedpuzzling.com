<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Navigation;

/**
 * One link of the site footer's columns (FooterBuilder): the registry entry (its label), where it leads for this page,
 * its data-nav place/item and how it is marked.
 */
final readonly class FooterLink
{
    public function __construct(
        public NavEntry $entry,
        public string $href,
        public string $nav,
        public bool $nofollow,
        public bool $newBadge,
        // Opens the Help sheet (help_opener_attrs() in the template)
        public bool $helpSheet,
    ) {
    }
}
