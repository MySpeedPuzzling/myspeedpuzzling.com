<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Value;

/**
 * Where the expected time of a solo result came from - the first available of the chain
 * (docs/features/suspicious-time-review.md, "The expected time").
 */
enum ExpectedTimeSource: string
{
    // The stored (or, in the form, live) prediction - made without knowledge of the solve
    case Prediction = 'prediction';
    // player_baseline for the piece count × the puzzle's difficulty when it has one
    case Baseline = 'baseline';
    // The player's other solo results within ±180 days, made comparable by the community pace per piece count
    case Pace = 'pace';
}
