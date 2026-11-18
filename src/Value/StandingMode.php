<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Value;

/**
 * How a standing combines its rounds (competition toolkit A6, A-round-formats.md §3.2). `sum` = the rounds' scores added
 * up per entrant (StandingCalculator::sum()). `stages` = the final classification across the bracket (competition
 * toolkit v2 S5, A §3.5, StandingCalculator::stages()): its rounds are the bracket's of its category, read from the
 * declared advancement steps at compute time - `round_ids` stays empty.
 */
enum StandingMode: string
{
    case Sum = 'sum';
    case Stages = 'stages';
}
