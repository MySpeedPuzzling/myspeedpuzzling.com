<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Value;

/**
 * What a column of an uploaded participant list holds. `TeamInRound` is the only field that may be mapped
 * more than once - once per pair/team round (the round's id travels next to it in ColumnMapping).
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
}
