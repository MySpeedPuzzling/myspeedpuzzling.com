<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Results;

use DateTimeImmutable;
use SpeedPuzzling\Web\Value\BadgeTier;
use SpeedPuzzling\Web\Value\BadgeType;
use SpeedPuzzling\Web\Value\XpReason;

/**
 * One audit-page line: the ledger entry plus enough context to link it back to
 * its solve (puzzle) or achievement. Deleted solves keep their entries — puzzle
 * context is null for those.
 */
readonly final class XpHistoryEntry
{
    public function __construct(
        public XpReason $reason,
        public int $amount,
        public DateTimeImmutable $earnedAt,
        public null|string $solvingTimeId,
        public null|string $puzzleId,
        public null|string $puzzleName,
        public null|BadgeType $badgeType,
        public null|BadgeTier $badgeTier,
    ) {
    }
}
