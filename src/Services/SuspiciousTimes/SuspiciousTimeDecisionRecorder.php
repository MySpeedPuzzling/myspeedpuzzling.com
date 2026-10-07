<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Services\SuspiciousTimes;

use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Entity\Player;
use SpeedPuzzling\Web\Entity\Puzzle;
use SpeedPuzzling\Web\Entity\PuzzleSolvingTime;
use SpeedPuzzling\Web\Entity\SuspiciousTimeCase;
use SpeedPuzzling\Web\Entity\SuspiciousTimeDecision;
use SpeedPuzzling\Web\Value\SuspiciousTimeDecisionKind;
use SpeedPuzzling\Web\Value\SuspiciousTimeReason;

/**
 * Writes the suspicious_time_decision row for a decision (docs/features/suspicious-time-review.md, "Decision log").
 * Called from inside the deciding handler, so the record commits (or rolls back) with the decision. No decider =
 * decided outside the app (SQL) or by the app itself.
 */
readonly final class SuspiciousTimeDecisionRecorder
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private ClockInterface $clock,
    ) {
    }

    /**
     * @param list<SuspiciousTimeReason> $reasonsShown
     */
    public function recordAboutTime(
        SuspiciousTimeDecisionKind $decision,
        PuzzleSolvingTime $time,
        null|SuspiciousTimeCase $case,
        null|Player $decidedBy,
        array $reasonsShown = [],
        null|string $note = null,
    ): void {
        $this->entityManager->persist(new SuspiciousTimeDecision(
            id: Uuid::uuid7(),
            decision: $decision,
            decidedAt: $this->clock->now(),
            puzzleId: $time->puzzle->id,
            timeId: $time->id,
            trackerId: $time->player->id,
            caseId: $case?->id,
            reasonsShown: SuspiciousTimeReason::listToArray($reasonsShown),
            note: self::note($note),
            snapshot: [
                'seconds' => $time->secondsToSolve,
                'pieces' => $time->puzzle->piecesCount,
                'puzzle_name' => $time->puzzle->name,
                'puzzling_type' => $time->puzzlingType->value,
                'puzzlers' => $time->puzzlersCount,
                'expected_seconds' => $case?->expectedSeconds,
                'expected_source' => $case?->expectedSource?->value,
                'detector_version' => $case?->detectorVersion,
                'tier' => $case?->tier?->value,
                'score' => $case?->score,
                'reasons' => $case->reasons ?? [],
                'fingerprint' => SuspicionFingerprint::ofTime($time),
            ],
            decidedById: $decidedBy?->id,
            decidedByName: $decidedBy?->name,
            decidedByCode: $decidedBy?->code,
        ));
    }

    /**
     * A decision about a puzzle (its slow threshold; pieces_confirmed earlier).
     *
     * @param array<string, mixed> $details
     */
    public function recordAboutPuzzle(
        SuspiciousTimeDecisionKind $decision,
        Puzzle $puzzle,
        null|Player $decidedBy,
        null|string $note = null,
        array $details = [],
    ): void {
        $this->entityManager->persist(new SuspiciousTimeDecision(
            id: Uuid::uuid7(),
            decision: $decision,
            decidedAt: $this->clock->now(),
            puzzleId: $puzzle->id,
            timeId: null,
            trackerId: null,
            caseId: null,
            reasonsShown: [],
            note: self::note($note),
            snapshot: [
                'pieces' => $puzzle->piecesCount,
                'puzzle_name' => $puzzle->name,
                ...$details,
            ],
            decidedById: $decidedBy?->id,
            decidedByName: $decidedBy?->name,
            decidedByCode: $decidedBy?->code,
        ));
    }

    private static function note(null|string $note): null|string
    {
        $note = $note !== null ? trim($note) : null;

        return $note !== '' ? $note : null;
    }
}
