<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Value;

/**
 * What a column of an uploaded participant list holds. `TeamInRound` and `DivisionInRound` are the only fields that may
 * be mapped more than once - once per round (the round's id travels next to it in ColumnMapping). `Division` and
 * `DivisionInRound` exist only for an event with divisions (shared-puzzles-and-divisions.md §3.5). `MemberName` may be
 * mapped to several columns (one per team member, in column order): then a row of the file is a team ticket - one
 * person per filled member cell (participant-import-preview.md "As built: team rows").
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
    // One member of the row's team ("Player 1 name" … "Player 12 name") - a row is then one team ticket
    case MemberName = 'member_name';
    // The ticket type of a registration export - which round it means is chosen per value on the preview
    case TicketType = 'ticket_type';
}
