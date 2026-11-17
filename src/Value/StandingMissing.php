<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Value;

/**
 * What a round an entrant has no score in does to them (A-round-formats.md §3.3). `incomplete`: they are listed after
 * every complete entrant, without a rank ("3 of 6 rounds"). `as_zero_pieces` is deferred to v2 (R-review-and-plan.md
 * §3).
 */
enum StandingMissing: string
{
    case Incomplete = 'incomplete';
}
