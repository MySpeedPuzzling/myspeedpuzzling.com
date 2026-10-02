<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Results;

use DateTimeImmutable;
use SpeedPuzzling\Web\Value\DuplicatePuzzleSignalReason;

/**
 * An open catalogue signal on the admin page (docs/features/duplicate-results.md, Layer 4).
 */
readonly final class DuplicatePuzzleSignalListItem
{
    public function __construct(
        public string $signalId,
        public DuplicatePuzzleSignalPuzzle $puzzleA,
        public DuplicatePuzzleSignalPuzzle $puzzleB,
        public int $matchingResults,
        public int $matchingPeople,
        public string $examplePlayerId,
        // Null = the player is gone
        public null|string $examplePlayerName,
        public null|string $examplePlayerCode,
        public int $exampleSeconds,
        public DateTimeImmutable $exampleDay,
        public DateTimeImmutable $detectedAt,
        public int $score,
        /** @var list<DuplicatePuzzleSignalReason> */
        public array $reasons,
        public float $nameSimilarity,
        public bool $weak,
    ) {
    }
}
