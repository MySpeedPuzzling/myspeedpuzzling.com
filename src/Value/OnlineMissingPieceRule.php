<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Value;

/**
 * A puzzle finished with a piece missing (docs/features/online-competitions/README.md §13, 11): no finish, or a finish
 * with 5 seconds added per missing piece (the WJPC rule).
 */
enum OnlineMissingPieceRule: string
{
    case CompleteRequired = 'complete_required';
    case PenaltyFiveSeconds = 'penalty_5s';
}
