<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Value;

/**
 * How a competition participant got linked to a MySpeedPuzzling account - `competition_participant.connected_via` and
 * `participant_link_log.method` (docs/features/competitions-management/participant-linking.md §3.1). NULL on a linked
 * row = linked before this was recorded.
 */
enum ParticipantLinkMethod: string
{
    // "I'm going" made or revived the player's own row
    case SelfJoin = 'self_join';
    // The player picked an unlinked name from the organiser's list (open claim policy)
    case Claim = 'claim';
    // The organiser approved the player's request
    case ClaimApproved = 'claim_approved';
    // The player opened the personal claim link the organiser handed them
    case ClaimLink = 'claim_link';
    // The player opened the online competition's personal link signed in (compete_connect)
    case OnlineLink = 'online_link';
    // The organiser linked the person in the participants sheet
    case Organiser = 'organiser';
    // The organiser's import file named the player
    case Import = 'import';

    /**
     * The player linked the row themselves - the import says so when it keeps the link (participant-linking.md §7.3)
     */
    public function isThePlayersOwn(): bool
    {
        return match ($this) {
            self::SelfJoin, self::Claim, self::ClaimApproved, self::ClaimLink, self::OnlineLink => true,
            self::Organiser, self::Import => false,
        };
    }
}
