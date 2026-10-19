<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Value;

/**
 * What the puzzle difficulty of a statistical prediction rests on: the puzzle's own solves (5+ indices, the
 * median), its few solves shrunk toward a prior (1-4 indices, or none and a prior alone), or the picture estimate
 * alone (the side-by-side `image` predictor, and `blend` at k = 0 - Insights 5d).
 */
enum DifficultySource: string
{
    case Solves = 'solves';
    case Shrunk = 'shrunk';
    case Image = 'image';
}
