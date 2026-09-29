<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Results;

/**
 * A puzzle used at a competition, with the public statistics every puzzle page shows: the solo median
 * and fastest time, and the fastest pair and team time.
 */
readonly final class CompetitionPuzzle
{
    public function __construct(
        public string $puzzleId,
        public string $puzzleName,
        public int $piecesCount,
        public null|string $manufacturerName,
        public null|string $puzzleImage,
        public null|float $puzzleImageRatio,
        public int $soloSolvesCount,
        public null|int $medianTimeSolo,
        public null|int $fastestTimeSolo,
        public null|int $fastestTimeDuo,
        public null|int $fastestTimeTeam,
    ) {
    }
}
