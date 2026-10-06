<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Value;

/**
 * What the review of a change request did with one proposed change (PuzzleChangeRequestOutcome).
 */
enum ProposedChangeResult: string
{
    // Saved as proposed
    case Applied = 'applied';
    // The reviewer saved another value than the proposed one
    case Altered = 'altered';
    // Left as it was - the request was rejected, or the reviewer did not take this change
    case NotApplied = 'not_applied';
}
