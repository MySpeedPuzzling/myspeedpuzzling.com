<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Results;

use SpeedPuzzling\Web\Value\PuzzleNames;

/**
 * Display data of a puzzle the Insights page shows (GetInsightsPuzzles): the inputs of puzzle/_name.html.twig (name +
 * other names) and of _puzzle_row_image.html.twig (image, ratio), the brand and the piece count.
 */
readonly final class InsightsPuzzle
{
    public function __construct(
        public string $puzzleId,
        public string $name,
        public PuzzleNames $alternativeNames,
        public null|string $manufacturerName,
        public int $piecesCount,
        public null|string $image,
        public null|float $imageRatio,
    ) {
    }
}
