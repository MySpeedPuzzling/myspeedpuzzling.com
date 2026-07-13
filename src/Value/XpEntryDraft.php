<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Value;

use DateTimeImmutable;
use Ramsey\Uuid\UuidInterface;

/**
 * A ledger entry about to be persisted by the XpLedger.
 *
 * earned_at rules (locked): solve-derived drafts carry COALESCE(solve.finished_at,
 * solve.tracked_at); achievement drafts carry badge.earned_at; settlement drafts the
 * settlement run time. Never clock-now for solve-derived drafts.
 */
final readonly class XpEntryDraft
{
    public function __construct(
        public XpReason $reason,
        public int $amount,
        public DateTimeImmutable $earnedAt,
        public bool $inWeeklyDelta,
        public null|UuidInterface $solvingTimeId = null,
        public null|UuidInterface $badgeId = null,
    ) {
    }
}
