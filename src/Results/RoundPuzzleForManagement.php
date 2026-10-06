<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Results;

use DateTimeImmutable;
use SpeedPuzzling\Web\Value\PuzzleHideMode;
use SpeedPuzzling\Web\Value\RoundPuzzleReveal;
use SpeedPuzzling\Web\Value\RoundPuzzleStatus;

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
        // This round still keeps it secret right now (its own setting - the organiser may change it)
        public bool $hidden = false,
        // What is effectively true right now - the line the organiser reads
        public null|RoundPuzzleStatus $status = null,
    ) {
    }
}
