<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Value;

/**
 * What opening a personal claim link did, or would do (ClaimParticipantByToken, the claim page's states -
 * docs/features/competitions-management/participant-linking.md §5.2, §6.5).
 */
enum ClaimLinkOutcome: string
{
    // Linked now (`claim_link`)
    case Linked = 'linked';
    // Unknown, cleared or removed participant, or an event not publicly visible - one neutral "not valid" for all
    case NotValid = 'not_valid';
    // Linked to this player already - nothing changed
    case AlreadyYours = 'already_yours';
    // Linked to another account - the page offers to tell the organiser (a dispute, ClaimParticipant)
    case BelongsToOther = 'belongs_to_other';
    // The player is on the event's list as another participant - the page offers the switch
    case IsOtherParticipant = 'is_other_participant';
    // The switch would let go of the player's own paid registration without their yes (`release_paid`) - nothing changed
    case ReleaseNotConfirmed = 'release_not_confirmed';
}
