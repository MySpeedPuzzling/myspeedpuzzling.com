<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Navigation;

/**
 * One chip of a place's sub-navigation (COM-02, PUZ-01): its section (the data-nav item), where it leads, its label
 * key (the registry entry's - "My library" excepted, SectionChipsBuilder) and whether it is the current one - "page" on
 * the exact page it links to, "true" deeper inside its section (decision 27, as the places).
 */
final readonly class SectionChip
{
    public function __construct(
        public string $section,
        public string $href,
        public string $label,
        public null|string $current = null,
    ) {
    }
}
