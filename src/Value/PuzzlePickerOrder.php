<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Value;

/**
 * Pick order of the puzzle picker. Random is the seeded shuffle; the gap orders
 * (members with predictions) put the puzzles with the largest gap between my
 * fastest time and my prediction first — puzzles without a gap sort last — with
 * the seed as the tie-breaker so the order stays reproducible.
 */
enum PuzzlePickerOrder: string
{
    case Random = 'random';
    case GapSlower = 'gap_slower';
    case GapFaster = 'gap_faster';

    public function isGapOrder(): bool
    {
        return $this !== self::Random;
    }
}
