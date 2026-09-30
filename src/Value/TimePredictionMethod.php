<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Value;

/**
 * How a stored solving-time prediction was made: from the player's own earlier solves of
 * the same puzzle, or from their baseline × the puzzle's difficulty.
 */
enum TimePredictionMethod: string
{
    case Personal = 'personal';
    case Statistical = 'statistical';
}
