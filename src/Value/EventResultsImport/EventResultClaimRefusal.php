<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Value\EventResultsImport;

/**
 * Why a claim of a spot of an imported event result - or its release or removal - was refused
 * (docs/features/event-results-import/README.md "Gates"). The value is the end of its translation key
 * `event_result_claim.refused.<value>`.
 */
enum EventResultClaimRefusal: string
{
    // Not a result of an organizer's sheet - an ordinary result is claimed by nobody
    case NotImported = 'not_imported';
    // Every spot of the team is claimed
    case TeamFull = 'team_full';
    // One spot per person per round: the player is already in a result of this round
    case AlreadyInRound = 'already_in_round';
    // Removed from two teams by other people - an admin lets them claim again
    case Blocked = 'blocked';
    // Somebody of the team already has another result of the puzzle marked as a first try
    case FirstTryTaken = 'first_try_taken';
    // "Is this the same result?" named a result that is not the player's own result of this puzzle
    case SameResultInvalid = 'same_result_invalid';
    // Release / removal of a spot the player does not hold
    case NotAClaimer = 'not_a_claimer';
    // Removal by somebody who is neither another claimer, nor the organizer's team, nor an admin
    case NotAllowed = 'not_allowed';
}
