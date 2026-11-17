<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Value;

/**
 * What a round an entrant has no score in does to them (A-round-formats.md §3.3).
 * - `incomplete`: they are listed after every complete entrant, without a rank ("3 of 6 rounds").
 * - `as_zero_pieces` (the World Series no-show, world-series-rules.md §4): a round they did not appear in counts as
 *   nothing placed - the time limit + the penalty × all pieces - and they stay ranked. It needs the round's time limit
 *   (else the round stays missing); a "no result" counts only once the round is final (its results are published).
 */
enum StandingMissing: string
{
    case Incomplete = 'incomplete';
    case AsZeroPieces = 'as_zero_pieces';
}
