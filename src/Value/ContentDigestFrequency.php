<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Value;

/**
 * Content-digest subscription (docs/features/content-digest/README.md §4).
 * Daily subscribes to BOTH digests: the weekly run targets daily+weekly,
 * the daily run targets daily only. v1 ships the weekly digest only.
 */
enum ContentDigestFrequency: string
{
    case None = 'none';
    case Daily = 'daily';
    case Weekly = 'weekly';
}
