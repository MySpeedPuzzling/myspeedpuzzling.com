<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Navigation;

/**
 * The chip row of a page (COM-02, PUZ-01; templates/navigation/_section_chips.html.twig): the place whose sections
 * it switches between (the data-nav place), the row's accessible name and its chips in order.
 */
final readonly class SectionChips
{
    /**
     * @param non-empty-list<SectionChip> $chips
     */
    public function __construct(
        public NavPlace $place,
        public string $label,
        public array $chips,
    ) {
    }
}
