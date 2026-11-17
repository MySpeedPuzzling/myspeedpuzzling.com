<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Value;

/**
 * What a row of the append-only `participant_link_log` records (docs/features/competitions-management/
 * participant-linking.md §3.4). Only `linked`, `unlinked` and `account_deleted` are written so far; the request and
 * refusal actions arrive with the claim requests (package L2) and invitations (L4b).
 */
enum ParticipantLinkAction: string
{
    case Linked = 'linked';
    case Unlinked = 'unlinked';
    case Requested = 'requested';
    case Approved = 'approved';
    case Declined = 'declined';
    case Withdrawn = 'withdrawn';
    case Superseded = 'superseded';
    // The player said the participant is not them
    case NotMe = 'not_me';
    // The organiser said the linked player is not this person
    case NotThisPerson = 'not_this_person';
    case AccountDeleted = 'account_deleted';
    case InvitationSent = 'invitation_sent';
}
