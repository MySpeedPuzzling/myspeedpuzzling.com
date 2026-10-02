<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Value;

/**
 * Which "Your results" e-mail a contact is (docs/features/duplicate-results.md, "Weekly 'Your results' e-mail").
 */
enum ResultReviewContactType: string
{
    // The backlog: everything found so far, to everybody with something to tell, active or not
    case First = 'first';
    // Later, at most weekly and only with something new, to players active in the last 3 months
    case Weekly = 'weekly';
}
