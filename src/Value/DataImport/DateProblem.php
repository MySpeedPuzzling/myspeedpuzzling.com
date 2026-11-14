<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Value\DataImport;

/**
 * What is wrong with a date that could be read - a problem of the row to fix, never a reason to change the date.
 */
enum DateProblem: string
{
    /** After today - a typo ("2028" among 2024) */
    case InTheFuture = 'in_the_future';
    /** Before 1950 */
    case Before1950 = 'before_1950';
}
