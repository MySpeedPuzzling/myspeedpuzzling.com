<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Message;

use SpeedPuzzling\Web\Value\PuzzleTagCorrectionVerdict;

/**
 * An admin's or a moderator's word on one tag of one puzzle (docs/features/insights/tags.md, "Corrections") - replaces
 * their earlier word on the same key. The tags themselves follow with RefreshPuzzleTags for the puzzle.
 */
readonly final class CorrectPuzzleTag
{
    public function __construct(
        public string $puzzleId,
        public string $tagKey,
        public PuzzleTagCorrectionVerdict $verdict,
        public null|string $note,
        public string $decidedByPlayerId,
    ) {
    }
}
