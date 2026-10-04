<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Query;

use SpeedPuzzling\Web\Results\PuzzleOverview;
use SpeedPuzzling\Web\Value\Ean;

/**
 * The puzzles a scanned code means: the lookup of the single scanner
 * (SearchPuzzle::allByEan - the code is one of the puzzle's EANs, leading zeros
 * stripped, never a part of a longer code; hidden puzzles excluded), most
 * solved first, so the tray's silent auto-pick is deterministic.
 */
readonly final class GetMultiscanCandidates
{
    public function __construct(
        private SearchPuzzle $searchPuzzle,
    ) {
    }

    /**
     * @return list<PuzzleOverview>
     */
    public function forEan(Ean $ean): array
    {
        return $this->searchPuzzle->allByEan($ean->digits);
    }
}
