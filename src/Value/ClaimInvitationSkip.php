<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Value;

/**
 * Why somebody gets no claim invitation (docs/features/competitions-management/participant-linking.md §5.5, §7.1) - the
 * skip counts of "Send invitations to N" and the reason a row's "Send invitation" is disabled. Nothing here says whether
 * an address has an account or takes our e-mail (D10): a suppressed address is dropped silently and counted as sent.
 */
enum ClaimInvitationSkip: string
{
    // Removed from the event, or not a participant of it
    case NotOnList = 'not_on_list';
    case Linked = 'linked';
    case NoEmail = 'no_email';
    // The person's one-click "no more invitations for this event"
    case SaidNo = 'said_no';
    // One per address per CompetitionParticipant::CLAIM_INVITATION_EVERY (over every row of the event with it)
    case InvitedRecently = 'invited_recently';
}
