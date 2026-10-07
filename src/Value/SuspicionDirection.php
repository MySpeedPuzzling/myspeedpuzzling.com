<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Value;

/**
 * Which way a raised time is off: much faster (hours left out, a group saved as solo, another edition) or much
 * slower (minutes typed into the hours box, days counted instead of puzzling time).
 */
enum SuspicionDirection: string
{
    case Fast = 'fast';
    case Slow = 'slow';
}
