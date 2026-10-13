<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Value;

/**
 * Where an evidence object lives (docs/features/online-competitions/README.md §5.11): the primary object storage, or
 * the second store that takes uploads while the primary is down.
 */
enum OnlineMediaStore: string
{
    case Primary = 'primary';
    case Secondary = 'secondary';
}
