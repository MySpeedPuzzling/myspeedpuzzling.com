<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Value;

enum EditTimeReturnContext: string
{
    case Profile = 'profile';
    case PuzzleDetail = 'puzzle-detail';
    case TimeRecap = 'time-recap';
    // "Awaiting verification" on "Review your results" (docs/features/suspicious-time-review.md)
    case ReviewResults = 'review-results';
}
