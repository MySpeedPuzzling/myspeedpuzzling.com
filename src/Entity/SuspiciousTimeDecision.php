<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Entity;

use DateTimeImmutable;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping\Column;
use Doctrine\ORM\Mapping\Entity;
use Doctrine\ORM\Mapping\Id;
use Doctrine\ORM\Mapping\Index;
use JetBrains\PhpStorm\Immutable;
use Ramsey\Uuid\Doctrine\UuidType;
use Ramsey\Uuid\UuidInterface;
use SpeedPuzzling\Web\Value\SuspiciousTimeDecisionKind;

/**
 * Append-only record of every decision about a suspicious time (docs/features/suspicious-time-review.md,
 * "Decision log"), written only by Services\SuspiciousTimes\SuspiciousTimeDecisionRecorder.
 *
 * Deliberately holds no foreign keys, like puzzle_moderation_decision: the time, its puzzle, the player and the
 * moderator can all be deleted - the log must outlive them. The decider's name and code are copied in for the same
 * reason; no decider = decided outside the app (SQL) or by the app itself (an automatic unmark after a fix).
 */
#[Entity]
#[Immutable]
#[Index(columns: ['decided_at'])]
#[Index(columns: ['time_id'])]
#[Index(columns: ['puzzle_id'])]
class SuspiciousTimeDecision
{
    /**
     * @param list<array{code: string, params: array<string, int|float|string|bool|null>}> $reasonsShown
     * @param array<string, mixed> $snapshot seconds, pieces, puzzle name, expected seconds + source, detector version, tier, score
     */
    public function __construct(
        #[Id]
        #[Column(type: UuidType::NAME, unique: true)]
        public UuidInterface $id,
        #[Column(type: Types::STRING, enumType: SuspiciousTimeDecisionKind::class)]
        public SuspiciousTimeDecisionKind $decision,
        #[Column(type: Types::DATETIME_IMMUTABLE)]
        public DateTimeImmutable $decidedAt,
        #[Column(type: UuidType::NAME)]
        public UuidInterface $puzzleId,
        // Null for a decision about a puzzle (pieces_confirmed, slow_threshold_set, slow_threshold_removed)
        #[Column(type: UuidType::NAME, nullable: true)]
        public null|UuidInterface $timeId,
        // The time's tracker
        #[Column(type: UuidType::NAME, nullable: true)]
        public null|UuidInterface $trackerId,
        #[Column(type: UuidType::NAME, nullable: true)]
        public null|UuidInterface $caseId,
        #[Column(type: Types::JSONB, options: ['default' => '[]'])]
        public array $reasonsShown,
        #[Column(type: Types::TEXT, nullable: true)]
        public null|string $note,
        #[Column(type: Types::JSONB)]
        public array $snapshot,
        #[Column(type: UuidType::NAME, nullable: true)]
        public null|UuidInterface $decidedById,
        #[Column(nullable: true)]
        public null|string $decidedByName,
        #[Column(nullable: true)]
        public null|string $decidedByCode,
    ) {
    }
}
