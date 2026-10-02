<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Value;

/**
 * What kind of twin a duplicate case is (docs/features/duplicate-results.md, "Definitions").
 */
enum DuplicateKind: string
{
    // The same tracker saved the same solo result twice on the same day
    case SameTracker = 'same_tracker';
    // The same tracker saved a pair/team result twice
    case SameTrackerGroup = 'same_tracker_group';
    // Two members of a pair/team each saved it
    case TeammateCopy = 'teammate_copy';
    // A person's solo result and a pair/team result with them
    case SoloAndGroup = 'solo_and_group';
    // Different days, but saved within an hour of each other - mostly a wrong date
    case SavedWithinHour = 'saved_within_hour';
}
