<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Value;

/**
 * What one check of a solo time found (docs/features/suspicious-time-review.md, "Checks and versions").
 */
enum SuspicionCheckOutcome: string
{
    // Fits the player's own expectation - final for the detector version
    case Clear = 'clear';
    // Nothing to judge it by yet (no expectation, not beyond the community) - checked again while solved in the last 180 days
    case NoData = 'no_data';
    // A case for a moderator
    case Raised = 'raised';
}
