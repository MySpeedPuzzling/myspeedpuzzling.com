<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Value;

/**
 * What the "Competition / event" field of the add/edit time forms holds (CompetitionPick): a one-time event, a series
 * (MySpeedPuzzling finds the edition) or an explicitly picked edition.
 */
enum CompetitionPickKind
{
    case Event;
    case Series;
    case Edition;
}
