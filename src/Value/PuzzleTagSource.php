<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Value;

/**
 * Where a puzzle_tag row comes from (docs/features/insights/tags.md).
 */
enum PuzzleTagSource: string
{
    // The `tags` analysis of the puzzle's current picture assigned it
    case Analysis = 'analysis';
    // An admin or a moderator added it (puzzle_tag_correction) - it stays whatever the next analysis says
    case Correction = 'correction';
}
