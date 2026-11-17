<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Value;

/**
 * How a standing combines its rounds (competition toolkit A6, A-round-formats.md §3.2). `sum` = the rounds' scores added
 * up per entrant (StandingCalculator::sum()). The bracket classification (`stages`, A §3.5) is a later package.
 */
enum StandingMode: string
{
    case Sum = 'sum';
}
