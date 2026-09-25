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
use SpeedPuzzling\Web\Value\MergeDecisionSource;
use SpeedPuzzling\Web\Value\PuzzleModerationAction;

/**
 * Append-only record of who decided what about the puzzle catalogue, and when.
 *
 * Deliberately holds no foreign keys: the requests, puzzles and brands it
 * points at can be deleted (a merge deletes puzzles, a change request cascades
 * with its reporter, a brand merge deletes the brand) and the decider's player
 * row can be deleted too - the log must outlive all of them. The decider's
 * name and code are copied in for the same reason.
 */
#[Entity]
#[Immutable]
#[Index(columns: ['decided_at'])]
#[Index(columns: ['decided_by_id'])]
#[Index(columns: ['puzzle_id'])]
class PuzzleModerationDecision
{
    /**
     * @param null|array<string, mixed> $details
     */
    public function __construct(
        #[Id]
        #[Column(type: UuidType::NAME, unique: true)]
        public UuidInterface $id,
        #[Column(type: Types::STRING, enumType: PuzzleModerationAction::class)]
        public PuzzleModerationAction $action,
        #[Column(type: Types::DATETIME_IMMUTABLE)]
        public DateTimeImmutable $decidedAt,
        #[Column(type: UuidType::NAME)]
        public UuidInterface $decidedById,
        #[Column(nullable: true)]
        public null|string $decidedByName,
        #[Column(nullable: true)]
        public null|string $decidedByCode,
        #[Column(type: Types::STRING, enumType: MergeDecisionSource::class)]
        public MergeDecisionSource $source,
        #[Column(type: UuidType::NAME, nullable: true)]
        public null|UuidInterface $puzzleId = null,
        #[Column(nullable: true)]
        public null|string $puzzleName = null,
        #[Column(type: UuidType::NAME, nullable: true)]
        public null|UuidInterface $changeRequestId = null,
        #[Column(type: UuidType::NAME, nullable: true)]
        public null|UuidInterface $mergeRequestId = null,
        #[Column(type: UuidType::NAME, nullable: true)]
        public null|UuidInterface $manufacturerId = null,
        #[Column(type: Types::TEXT, nullable: true)]
        public null|string $note = null,
        #[Column(type: Types::JSON, nullable: true)]
        public null|array $details = null,
    ) {
    }
}
