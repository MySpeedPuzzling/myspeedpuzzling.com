<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Value;

/**
 * Live = predicted when the time was added, from the insights tables as they were right then
 * (only for solves of the adding day or the day before). Reconstructed = today's model fed only
 * with the data that existed before the solve (backfill, back-dated adds, edits).
 */
enum TimePredictionSource: string
{
    case Live = 'live';
    case Reconstructed = 'reconstructed';
}
