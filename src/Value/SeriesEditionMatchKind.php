<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Value;

/**
 * How MySpeedPuzzling found the edition of a series pick (puzzle_solving_time.series_edition_match,
 * docs/features/events-page/high-frequency-series.md "The matching rule"): by a revealed round puzzle of the time's
 * category (rule 1) or by the solve day (rule 2). Null on a series pick = series-level, no edition identified; always
 * null on an explicit link.
 */
enum SeriesEditionMatchKind: string
{
    case Puzzle = 'puzzle';
    case Date = 'date';
}
