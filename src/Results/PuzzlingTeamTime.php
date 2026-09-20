<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Results;

use DateTimeImmutable;

readonly final class PuzzlingTeamTime
{
    public function __construct(
        public string $timeId,
        public string $puzzleId,
        public string $puzzleName,
        public string $manufacturerName,
        public int $piecesCount,
        public null|string $puzzleImage,
        // Null for a relax result (no time measured)
        public null|int $time,
        public DateTimeImmutable $solvedAt,
        public bool $firstAttempt,
        public bool $unboxed,
    ) {
    }
}
