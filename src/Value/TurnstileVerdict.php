<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Value;

enum TurnstileVerdict
{
    case Passed;
    case Failed;
    // siteverify did not answer
    case Unavailable;
}
