<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Value;

/**
 * What a signed-in player opening a personal link means for the participant ↔ player connection
 * (docs/features/online-competitions/README.md §5.4, OnlineConnectRules) - the `status` of `compete_connect`:
 * - Connected: connected automatically (or, for an organiser, after "This is me" - `confirm=1`);
 * - AlreadyConnected: the participant is this player already - nothing changes;
 * - BelongsToOther: the participant is another account's - nothing changes, the link still works for competing;
 * - IsOtherParticipant: the player is another participant of this competition - never connected automatically, the
 *   organiser sorts it out in the participants sheet;
 * - OrganiserConfirmRequired: an organiser, maintainer or admin previewing a participant's link - connected only on
 *   the explicit "This is me".
 */
enum OnlineConnectStatus: string
{
    case Connected = 'connected';
    case AlreadyConnected = 'already_connected';
    case BelongsToOther = 'belongs_to_other';
    case IsOtherParticipant = 'is_other_participant';
    case OrganiserConfirmRequired = 'organiser_confirm_required';
}
