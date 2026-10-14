<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Value;

/**
 * The automated completeness check of a finish photo (docs/features/online-competitions/README.md §5.8, Phase 3) -
 * advisory, never a decision: `complete` (no gap found), `gaps` (suspected holes, with boxes), `unsure` (every doubt -
 * a hand over the puzzle, a motif in the mat's colour, an unfinished puzzle) or `failed` (the check could not run on
 * this photo - the worker refused it or never answered; a person looks, as without the check).
 */
enum OnlineFinishCheckVerdict: string
{
    case Complete = 'complete';
    case Gaps = 'gaps';
    case Unsure = 'unsure';
    case Failed = 'failed';
}
