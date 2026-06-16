<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Results;

use SpeedPuzzling\Web\Value\DifficultyTier;

readonly final class ComparisonPuzzleRow
{
    public function __construct(
        public string $puzzleId,
        public string $puzzleName,
        public null|string $puzzleAlternativeName,
        public string $manufacturerId,
        public string $manufacturerName,
        public int $piecesCount,
        public null|string $puzzleImage,
        public null|DifficultyTier $difficultyTier,
        public null|float $difficultyScore,
        /** @var list<ComparisonCell> solvers first (by time), then non-solvers */
        public array $cells,
        public int $solvedCount,
        public int $totalSubjects,
        public int $bestTime,
    ) {
    }

    public function isCommon(): bool
    {
        return $this->solvedCount === $this->totalSubjects && $this->totalSubjects > 0;
    }
}
