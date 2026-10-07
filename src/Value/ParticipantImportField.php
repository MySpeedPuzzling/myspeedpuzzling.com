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
    case PlayerId = 'msp_player_id';
    case ParticipantId = 'participant_id';
    case ExternalId = 'external_id';
    case Status = 'status';
}
