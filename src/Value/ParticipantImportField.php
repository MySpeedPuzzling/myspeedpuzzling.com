<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Value;

/**
 * What a column of an uploaded participant list holds. `TeamInRound` and `DivisionInRound` are the only fields that may
 * be mapped more than once - once per round (the round's id travels next to it in ColumnMapping). `Division` and
 * `DivisionInRound` exist only for an event with divisions (shared-puzzles-and-divisions.md §3.5).
 */
enum ParticipantImportField: string
{
    case Ignore = 'ignore';
    case Name = 'name';
    case FirstName = 'first_name';
    case LastName = 'last_name';
    case Country = 'country';
    case Rounds = 'round_names';
    case Round = 'round_name';
    case Team = 'team_name';
    case TeamInRound = 'team_in_round';
    // A MySpeedPuzzling player: id, #code or profile link (PlayerReference)
    case PlayerId = 'msp_player_id';
    // The export's `msp_code` column next to `msp_player_id`: read for a row whose player column is empty (a person
    // the organiser added to an export by hand) - the id wins where both are filled
    case PlayerCode = 'msp_code';
    case ParticipantId = 'participant_id';
    case ExternalId = 'external_id';
    case Status = 'status';
    // The organiser's address of the person, for the claim invitation (participant-linking.md §7.3) - private
    case Email = 'email';
    // The entry's division in every round of the row that uses it
    case Division = 'division';
    // The entry's division in one round (`division_in_round:<round id>`)
    case DivisionInRound = 'division_in_round';
}
