<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Results;

use DateTimeImmutable;
use SpeedPuzzling\Web\Value\XpReason;

/**
 * One receipt/audit line rendered from the XP ledger.
 */
readonly final class XpEntryLine
{
    public function __construct(
        public XpReason $reason,
        public int $amount,
        public DateTimeImmutable $earnedAt,
        public null|string $solvingTimeId,
        public null|string $badgeId,
    ) {
    }
}
