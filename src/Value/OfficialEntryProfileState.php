<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Value;

/**
 * What the public round page offers the signed-in viewer on one official results row
 * (docs/features/competitions-management/official-results.md, "Add to my profile"). Derived on every read from the
 * viewer's own times in the round - nothing about the link between a time and an entry is stored.
 */
enum OfficialEntryProfileState: string
{
    // "Add to my profile": the add-time form filled in from the entry
    case Offer = 'offer';

    // "On your profile": the viewer has a time in this round already
    case OnProfile = 'on_profile';

    // "This is my time - link it to <round>": the viewer has a time on the round's puzzle in this event that no round
    // holds yet (a puzzle several rounds share) - linking it beats adding the same solve twice
    // (shared-puzzles-and-divisions.md §2.4 row 15)
    case LinkToRound = 'link_to_round';
}
