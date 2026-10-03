<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Results;

/**
 * The three people lists of the Players page spotlight, at most GetSpotlightPeople::LIMIT each
 * (docs/features/players-page/README.md). The list keys are the "Browse all" directory's sorts.
 */
readonly final class SpotlightPeople
{
    public const string MOST_ACTIVE = 'active';
    public const string MOST_FOLLOWED = 'followed';
    public const string NEW_FACES = 'newest';

    /**
     * @param list<SpotlightPerson> $mostActive
     * @param list<SpotlightPerson> $mostFollowed
     * @param list<SpotlightPerson> $newFaces
     */
    public function __construct(
        public array $mostActive,
        public array $mostFollowed,
        public array $newFaces,
    ) {
    }
}
