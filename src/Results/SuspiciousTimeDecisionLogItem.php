<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Results;

use DateTimeImmutable;
use SpeedPuzzling\Web\Value\SuspiciousTimeDecisionKind;
use SpeedPuzzling\Web\Value\SuspiciousTimeReason;

/**
 * One row of suspicious_time_decision as the "Decision log" tab shows it - mostly its snapshot: the time, the
 * puzzle, the player and the moderator may all be gone.
 */
readonly final class SuspiciousTimeDecisionLogItem
{
    /**
     * @param list<SuspiciousTimeReason> $reasonsShown
     */
    public function __construct(
        public string $decisionId,
        public SuspiciousTimeDecisionKind $decision,
        public DateTimeImmutable $decidedAt,
        public string $puzzleId,
        public null|string $puzzleName,
        public null|int $piecesCount,
        // A competition keeps the puzzle secret now: no name, piece count or reasons until the reveal
        public bool $puzzleSecret,
        public bool $puzzleExists,
        public null|string $timeId,
        public bool $timeExists,
        public null|int $seconds,
        public null|int $expectedSeconds,
        public null|string $trackerId,
        public null|string $trackerName,
        public null|string $trackerCode,
        public array $reasonsShown,
        public null|string $note,
        public null|string $decidedById,
        public null|string $decidedByName,
        public null|string $decidedByCode,
    ) {
    }

    public function label(): string
    {
        return match ($this->decision) {
            SuspiciousTimeDecisionKind::Marked => 'Needs verification',
            SuspiciousTimeDecisionKind::Trusted => 'Looks fine',
            SuspiciousTimeDecisionKind::Unmarked => 'Unmarked',
            SuspiciousTimeDecisionKind::KeptAfterReply => 'Kept marked after a reply',
            SuspiciousTimeDecisionKind::CorrectedAutomatically => 'The entry changed and passes - unmarked automatically',
            SuspiciousTimeDecisionKind::MarkedOutsideApp => 'Flagged outside the app',
            SuspiciousTimeDecisionKind::UnmarkedOutsideApp => 'Unflagged outside the app',
            SuspiciousTimeDecisionKind::PiecesConfirmed => 'Piece count is right',
        };
    }
}
