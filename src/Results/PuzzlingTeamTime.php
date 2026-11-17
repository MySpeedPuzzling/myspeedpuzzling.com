<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Results;

use DateTimeImmutable;
use SpeedPuzzling\Web\Value\DatePrecision;

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
        // Listed with a label on the team's own page, never one of its best times
        public bool $suspicious = false,
        // How exactly the solve day is known (date-precision.md): a day, or only its month or year
        public DatePrecision $datePrecision = DatePrecision::Day,
        // An unfinished competition result: pieces placed when the round's time ran out - not a solve (CountsAsSolve)
        public null|int $piecesPlaced = null,
        public null|int $finishedLaterSeconds = null,
        // A relay team's result (docs/features/event-results-import/solo-and-relays.md §2): no time of its own - the
        // team's total of all the relay's puzzles and how many puzzles
        public null|int $relayTotalSeconds = null,
        public int $relayPuzzleCount = 0,
    ) {
    }

    public function isRelay(): bool
    {
        return $this->relayTotalSeconds !== null;
    }
}
