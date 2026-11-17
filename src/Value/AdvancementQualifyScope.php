<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Value;

/**
 * What the "how many qualify" number of an advancement step counts (competition toolkit A8, A-round-formats.md §3.5).
 */
enum AdvancementQualifyScope: string
{
    // The best N of each source round (WJPC: "the best 25 of each group go to the semifinals")
    case PerRound = 'per_round';
    // The best N over all the source rounds together, by the advancement seed (AdvancementSeeding)
    case Overall = 'overall';
}
