<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Value\EventResultsImport;

/**
 * What happened to a spot of an imported event result (docs/features/event-results-import/README.md "Gates").
 */
enum ResultClaimAction: string
{
    case Claimed = 'claimed';
    // The player took their spot back themselves ("Remove from my profile")
    case Released = 'released';
    // Somebody else said the player was not on the team: another claimer, the organizer's team or an admin
    case Removed = 'removed';
    case AccountDeleted = 'account_deleted';
}
