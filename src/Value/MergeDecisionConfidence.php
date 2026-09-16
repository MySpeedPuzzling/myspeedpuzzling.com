<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Value;

/**
 * How sure the reviewer was that the merged puzzles really were the same product.
 *
 * Recorded so that merges decided on weak evidence can be re-examined first,
 * without having to re-derive the reasoning from the snapshot.
 */
enum MergeDecisionConfidence: string
{
    // Same product beyond doubt: identical artwork, piece count and manufacturer.
    case High = 'high';
    // Same product on balance, but something disagreed - a rebrand, a re-release,
    // a manufacturer recorded under two names.
    case Medium = 'medium';
    // Merged on thin evidence. Review these before anything else.
    case Low = 'low';
}
