<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Results;

use DateTimeImmutable;

/**
 * Aggregated comparison data for a single subject on a single puzzle:
 * the fastest time (with date) and the first-try time (with date), if any.
 */
readonly final class ComparisonResultRow
{
    public function __construct(
        public string $puzzleId,
        public string $puzzleName,
        public null|string $puzzleAlternativeName,
        public string $manufacturerId,
        public string $manufacturerName,
        public int $piecesCount,
        public null|string $puzzleImage,
        public string $fastestTimeId,
        public int $fastestTime,
        public DateTimeImmutable $fastestDate,
        public null|string $firstTryTimeId,
        public null|int $firstTryTime,
        public null|DateTimeImmutable $firstTryDate,
    ) {
    }
}
