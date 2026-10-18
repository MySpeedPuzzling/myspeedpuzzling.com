<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Message;

/**
 * Gives one tag of one puzzle back to the analysis (docs/features/insights/tags.md, "Corrections"). Nothing to remove
 * is fine. The tags follow with RefreshPuzzleTags for the puzzle.
 */
readonly final class RemovePuzzleTagCorrection
{
    public function __construct(
        public string $puzzleId,
        public string $tagKey,
    ) {
    }
}
