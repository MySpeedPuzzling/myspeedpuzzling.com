<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Results;

use DateTimeImmutable;

/**
 * One automatic removal on the admin overview (/admin/duplicate-results).
 */
readonly final class AutoRemovalListItem
{
    public function __construct(
        public string $removalId,
        // The tracker of the removed copy
        public string $playerId,
        public null|string $playerName,
        public string $playerCode,
        public string $puzzleId,
        public string $puzzleName,
        public null|int $seconds,
        public DateTimeImmutable $solvedAt,
        public DateTimeImmutable $savedAt,
        public bool $isGroup,
        public string $removedTimeId,
        public string $keptTimeId,
        public DateTimeImmutable $removedAt,
        public null|DateTimeImmutable $undoneAt,
    ) {
    }
}
