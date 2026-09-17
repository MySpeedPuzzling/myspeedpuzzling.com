<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Value;

enum RoundResultStatus: string
{
    // Solved within the round's time limit - ranked by time
    case Finished = 'finished';
    // Time ran out, pieces placed reported - ranked by pieces placed, after every finished result
    case Unfinished = 'unfinished';
    // A time over the limit with no pieces reported (kept solving after the whistle) - ranked last, by time
    case OverLimit = 'over_limit';
}
