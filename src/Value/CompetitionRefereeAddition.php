<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Value;

/**
 * What AddCompetitionReferee did (read it from the envelope's HandledStamp) - the referees page tells the organiser.
 */
enum CompetitionRefereeAddition: string
{
    case Added = 'added';
    // Nothing to do - the player referees this competition already
    case AlreadyReferee = 'already_referee';
    // Nothing to do - an organiser (creator, maintainer, series owner/maintainer) may do everything a referee may
    case Organiser = 'organiser';
    case UnknownPlayer = 'unknown_player';
    case LimitReached = 'limit_reached';
}
