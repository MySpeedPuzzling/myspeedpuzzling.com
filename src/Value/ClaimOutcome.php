<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Value;

/**
 * What a claim did (ParticipantClaims::claim(), docs/features/competitions-management/participant-linking.md §5.1) -
 * the controller turns it into a flash or a page state.
 */
enum ClaimOutcome: string
{
    // Open policy: linked at once
    case Linked = 'linked';
    // Approval policy: waits for the organiser
    case Requested = 'requested';
    // The row is linked to another account: the organiser was asked to check it
    case DisputeSent = 'dispute_sent';
    // Linked to this player already - nothing changed
    case AlreadyYours = 'already_yours';
    // A refused pair, or the player's 3 refusals at this event - neutral: "Please ask the organiser to link you"
    case AskOrganiser = 'ask_organiser';
    // The participant is removed or of another event
    case NotAvailable = 'not_available';
    // The player's own paid registration would be let go of without their explicit yes (`release_paid`)
    case ReleaseNotConfirmed = 'release_not_confirmed';
}
