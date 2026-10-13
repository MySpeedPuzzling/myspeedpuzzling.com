<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Value;

/**
 * Where a confirmed finish moment came from (docs/features/online-competitions/README.md §5.9): a detection candidate,
 * the participant's Finished tap, or a moment the reviewer picked in the burst.
 */
enum OnlineConfirmedSource: string
{
    case Detection = 'detection';
    case Button = 'button';
    case Reviewer = 'reviewer';
}
