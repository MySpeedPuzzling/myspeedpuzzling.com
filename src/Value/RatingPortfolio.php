<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Value;

/**
 * The two portfolios of the MSP rating (docs/features/puzzle-intelligence/README.md, "MSP-ELO Ladder"): a player's
 * first attempts and their best times, each its own list of entries, blended 75 / 25.
 */
enum RatingPortfolio: string
{
    case FirstAttempt = 'first_attempt';
    case BestTime = 'best_time';
}
