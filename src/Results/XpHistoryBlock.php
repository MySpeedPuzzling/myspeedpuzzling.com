<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Results;

use DateTimeImmutable;
use SpeedPuzzling\Web\Value\BadgeTier;
use SpeedPuzzling\Web\Value\BadgeType;
use SpeedPuzzling\Web\Value\XpReason;

/**
 * One block of the XP history ("where did my XP come from?"): every ledger line of one solve - the solve's own lines,
 * the bonuses that settled later and the compensation when it was deleted - or one stand-alone entry (an achievement
 * from before achievements stopped granting XP).
 */
readonly final class XpHistoryBlock
{
    /**
     * @param list<XpHistoryEntry> $lines oldest first
     */
    public function __construct(
        public null|string $solvingTimeId,
        public null|string $puzzleId,
        public null|string $puzzleName,
        /** When the puzzle was solved (null once the solve is deleted) */
        public null|DateTimeImmutable $solvedAt,
        public null|string $puzzlingType,
        public bool $isTimed,
        /** 1 = the player's first solve of this puzzle, 2 = the second, … (null for deleted solves) */
        public null|int $occurrenceIndex,
        public null|BadgeType $badgeType,
        public null|BadgeTier $badgeTier,
        public array $lines,
    ) {
    }

    public function total(): int
    {
        $total = 0;

        foreach ($this->lines as $line) {
            $total += $line->amount;
        }

        return $total;
    }

    public function isSolve(): bool
    {
        return $this->solvingTimeId !== null;
    }

    public function isDeletedSolve(): bool
    {
        return $this->solvingTimeId !== null && $this->puzzleId === null;
    }

    public function lastEarnedAt(): DateTimeImmutable
    {
        $last = $this->lines[0]->earnedAt;

        foreach ($this->lines as $line) {
            if ($line->earnedAt > $last) {
                $last = $line->earnedAt;
            }
        }

        return $last;
    }

    /** The label of a line - the base line says when a repeat or relax discount applied (like the receipt) */
    public function lineLabelKey(XpHistoryEntry $line): string
    {
        if ($line->reason !== XpReason::SolveBase) {
            return 'xp.line.' . $line->reason->value;
        }

        if ($this->puzzleId !== null && $this->isTimed === false) {
            return 'xp.line.base_relax';
        }

        return match (true) {
            $this->occurrenceIndex === null || $this->occurrenceIndex <= 1 => 'xp.line.base',
            $this->occurrenceIndex === 2 => 'xp.line.base_repeat_second',
            default => 'xp.line.base_repeat_later',
        };
    }

    /** A line written later than the solve itself (a settled bonus, the deletion) shows its own date */
    public function isLaterLine(XpHistoryEntry $line): bool
    {
        return $line->reason === XpReason::DifficultySettlement
            || $line->reason === XpReason::SpeedSettlement
            || $line->reason === XpReason::SolveCompensation;
    }
}
