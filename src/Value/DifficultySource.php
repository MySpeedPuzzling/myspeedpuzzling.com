<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Value;

/**
 * What the puzzle difficulty of a statistical prediction rests on: the puzzle's own solves (5+ indices, the
 * median), its few solves shrunk toward a prior (1-4 indices, or none and a prior alone), or an image estimate
 * alone (Phase 5 of the Insights plan).
 */
enum DifficultySource: string
{
    case Solves = 'solves';
    case Shrunk = 'shrunk';
    case Image = 'image';
}
