<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Results;

/**
 * What the Solo add sheet of the compare page lists before anything is typed (GetComparisonPeople::forViewer()).
 */
readonly final class ComparisonPeople
{
    public function __construct(
        /** @var list<PlayerIdentification> every favorite the viewer may compare, by name */
        public array $favorites,
        /** @var list<PlayerIdentification> who the viewer puzzles with most, favorites left out */
        public array $coPuzzlers,
    ) {
    }
}
