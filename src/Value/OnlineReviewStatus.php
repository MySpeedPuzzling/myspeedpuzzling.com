<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Value;

/**
 * A participant's online entry in the review (docs/features/online-competitions/README.md §5.9): not looked at yet,
 * confirmed (the official result is recorded), or flagged for a second look.
 */
enum OnlineReviewStatus: string
{
    case Pending = 'pending';
    case Confirmed = 'confirmed';
    case Flagged = 'flagged';
}
