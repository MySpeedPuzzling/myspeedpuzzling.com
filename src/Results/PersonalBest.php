<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Results;

use DateTimeImmutable;
use SpeedPuzzling\Web\Value\PiecesRange;
use SpeedPuzzling\Web\Value\PuzzleNames;

/**
 * A player's fastest solo result of one piece-count preset (PiecesRange::PRESETS) - one row of the Overview's personal
 * bests (GetPlayerOverviewNumbers, docs/features/navigation README §4.7, PROF-03). Suspicious and relax results are
 * never a best; the image is masked while the puzzle hides it.
 */
readonly final class PersonalBest
{
    public function __construct(
        public PiecesRange $preset,
        public string $timeId,
        public string $puzzleId,
        public string $puzzleName,
        public PuzzleNames $puzzleAlternativeNames,
        public string $manufacturerName,
        public int $piecesCount,
        public null|string $puzzleImage,
        public null|float $puzzleImageRatio,
        public int $seconds,
        public DateTimeImmutable $solvedAt,
    ) {
    }
}
