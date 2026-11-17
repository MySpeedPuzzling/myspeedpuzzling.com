<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Value;

/**
 * The kinds of a printed competition tag (docs/features/competitions-management/toolkit/R-review-and-plan.md §1.3) -
 * the value is the letter of its frozen path `/live/{competitionId}/{letter}/{id}`.
 */
enum CompetitionTagKind: string
{
    // A person for the whole competition - their current round's entry (the name tag, /{_locale}/live/{cid}/p/{id})
    case Participant = 'p';
    // A pair/team by its exact member set, for the whole competition (CompetitionLineupId)
    case Lineup = 'g';
    // A seating table number: whoever sits there in the current round
    case Table = 't';
    // One round's entry (CompetitionParticipantRound or CompetitionTeam id) - static, its own round
    case Entry = 'e';
}
