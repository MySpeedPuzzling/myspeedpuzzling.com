<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Value;

/**
 * Where a result was saved from (puzzle_solving_time.created_via), so the duplicate-results numbers can tell which
 * path still saves twice (docs/features/duplicate-results.md). Results saved before it existed have none.
 */
enum SolvingTimeSource: string
{
    case Form = 'form';
    case Stopwatch = 'stopwatch';
    case Api = 'api';
}
