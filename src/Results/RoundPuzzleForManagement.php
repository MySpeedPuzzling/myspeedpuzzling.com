<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Results;

use DateTimeImmutable;
use SpeedPuzzling\Web\Value\PuzzleHideMode;
use SpeedPuzzling\Web\Value\RoundPuzzleReveal;

readonly final class RoundPuzzleForManagement
{
    public function __construct(
        public string $roundPuzzleId,
        public string $puzzleId,
        public string $puzzleName,
        public int $piecesCount,
        public null|string $puzzleImage,
        public null|string $manufacturerName,
        public bool $hideUntilRoundStarts,
        public null|PuzzleHideMode $hideMode = null,
        public RoundPuzzleReveal $revealMode = RoundPuzzleReveal::Automatic,
        // The one reveal moment (RoundPuzzleReveal), null = a manual reveal not made yet
        public null|DateTimeImmutable $revealsAt = null,
        // The round keeps the puzzle secret on the whole site (it was created for the round)
        public bool $hidesEverywhere = false,
        // Still secret right now
        public bool $hidden = false,
    ) {
    }
}
