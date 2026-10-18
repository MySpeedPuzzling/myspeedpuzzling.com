<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Results;

use DateTimeImmutable;
use SpeedPuzzling\Web\Value\PuzzleTagCorrectionVerdict;

/**
 * An admin's or a moderator's word on one tag of a puzzle (puzzle_tag_correction).
 */
readonly final class PuzzleTagCorrectionResult
{
    public function __construct(
        public string $tagKey,
        public PuzzleTagCorrectionVerdict $verdict,
        public null|string $note,
        public null|string $decidedById,
        public DateTimeImmutable $decidedAt,
        public string $taxonomyVersion,
    ) {
    }
}
