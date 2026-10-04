<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Results;

/**
 * Display data of one compared puzzle (GetComparisonPuzzles), for the rows of the shown page. The image is masked
 * while `hide_image_until` is in the future. Inputs of `_puzzle_row_image.html.twig`: image, ratio.
 */
readonly final class ComparisonPuzzle
{
    public function __construct(
        public string $puzzleId,
        public string $name,
        public null|string $manufacturerName,
        public int $piecesCount,
        public null|string $image,
        public null|float $imageRatio,
    ) {
    }
}
