<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Query;

use SpeedPuzzling\Web\Results\PuzzleOverview;
use SpeedPuzzling\Web\Value\Ean;

/**
 * The puzzles a scanned code may mean, exact matches first.
 *
 * Reuses the tolerant lookup of the single scanner (SearchPuzzle::allByEan -
 * substring match, zeros stripped, hidden puzzles excluded) and puts the
 * puzzles whose code list literally carries the scanned code before the
 * substring matches, so the tray's silent auto-pick is deterministic.
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
        $candidates = $this->searchPuzzle->allByEan($ean->digits);

        $exact = [];
        $loose = [];

        foreach ($candidates as $candidate) {
            if ($ean->isListedIn($candidate->puzzleEan)) {
                $exact[] = $candidate;
            } else {
                $loose[] = $candidate;
            }
        }

        return [...$exact, ...$loose];
    }
}
