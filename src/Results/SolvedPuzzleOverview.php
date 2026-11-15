<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Results;

use DateTimeImmutable;
use SpeedPuzzling\Web\Value\DatePrecision;
use SpeedPuzzling\Web\Value\PuzzleNames;

readonly final class SolvedPuzzleOverview
{
    public function __construct(
        public string $puzzleId,
        public string $puzzleName,
        public PuzzleNames $puzzleAlternativeNames,
        // The stored search keys - every name, every code, folded - that the list's search filter reads
        public null|string $searchNames,
        public null|string $searchCodes,
        public int $piecesCount,
        public null|string $manufacturerName,
        public null|string $image,
        public null|float $imageRatio,
        public null|DateTimeImmutable $finishedAt,
        // How exactly the solve day is known (date-precision.md): a day, or only its month or year
        public DatePrecision $datePrecision = DatePrecision::Day,
    ) {
    }
}
