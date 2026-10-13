<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Value;

/**
 * What a participant who did not finish reports at time-up (docs/features/online-competitions/README.md §5.10) - the
 * competition's choice: the pieces still left, or the pieces placed.
 */
enum OnlineUnfinishedMeasure: string
{
    case PiecesLeft = 'left';
    case PiecesPlaced = 'placed';
}
