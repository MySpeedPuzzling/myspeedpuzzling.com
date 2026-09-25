<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Services;

use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;
use Ramsey\Uuid\Uuid;
use Ramsey\Uuid\UuidInterface;
use SpeedPuzzling\Web\Entity\PuzzleModerationDecision;
use SpeedPuzzling\Web\Entity\Player;
use SpeedPuzzling\Web\Value\MergeDecisionSource;
use SpeedPuzzling\Web\Value\PuzzleModerationAction;

/**
 * Writes the puzzle_moderation_decision row for a decision. Called from inside the
 * deciding handler, so the record commits (or rolls back) with the decision.
 */
readonly final class PuzzleModerationDecisionRecorder
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private ClockInterface $clock,
    ) {
    }

    /**
     * @param null|array<string, mixed> $details
     */
    public function record(
        PuzzleModerationAction $action,
        Player $decidedBy,
        MergeDecisionSource $source = MergeDecisionSource::AdminUi,
        null|UuidInterface $puzzleId = null,
        null|string $puzzleName = null,
        null|UuidInterface $changeRequestId = null,
        null|UuidInterface $mergeRequestId = null,
        null|UuidInterface $manufacturerId = null,
        null|string $note = null,
        null|array $details = null,
    ): void {
        $this->entityManager->persist(new PuzzleModerationDecision(
            id: Uuid::uuid7(),
            action: $action,
            decidedAt: $this->clock->now(),
            decidedById: $decidedBy->id,
            decidedByName: $decidedBy->name,
            decidedByCode: $decidedBy->code,
            source: $source,
            puzzleId: $puzzleId,
            puzzleName: $puzzleName,
            changeRequestId: $changeRequestId,
            mergeRequestId: $mergeRequestId,
            manufacturerId: $manufacturerId,
            note: $note,
            details: $details,
        ));
    }
}
