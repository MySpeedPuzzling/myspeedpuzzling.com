<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Results;

use DateTimeImmutable;
use SpeedPuzzling\Web\Value\PuzzleTagSource;

/**
 * A stored tag of a puzzle's picture (puzzle_tag, docs/features/insights/tags.md).
 */
readonly final class PuzzleTagResult
{
    public function __construct(
        public string $puzzleId,
        public string $tagKey,
        public null|float $score,
        public bool $uncertain,
        public PuzzleTagSource $source,
        public null|string $analysisVersion,
        public null|string $taxonomyVersion,
        public DateTimeImmutable $updatedAt,
    ) {
    }
}
