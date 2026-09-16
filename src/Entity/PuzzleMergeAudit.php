<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Entity;

use DateTimeImmutable;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping\Column;
use Doctrine\ORM\Mapping\Entity;
use Doctrine\ORM\Mapping\Id;
use Doctrine\ORM\Mapping\Index;
use Doctrine\ORM\Mapping\JoinColumn;
use Doctrine\ORM\Mapping\ManyToOne;
use JetBrains\PhpStorm\Immutable;
use Ramsey\Uuid\Doctrine\UuidType;
use Ramsey\Uuid\UuidInterface;
use SpeedPuzzling\Web\Value\MergeDecisionConfidence;
use SpeedPuzzling\Web\Value\MergeDecisionSource;

/**
 * Forensic record of an approved puzzle merge.
 *
 * A merge is destructive: the merged puzzles are deleted and their solving times,
 * collection items, wish-list/sell-swap entries and lendings are moved onto the
 * survivor (or dropped where the survivor already had an equivalent row). Nothing
 * in the domain model remembers what those rows looked like beforehand, so a merge
 * approved in error could not be reconstructed.
 *
 * This entity stores the complete before/after state of every merge so one can be
 * reviewed after the fact and, if wrong, unpicked by hand. Puzzle ids are plain
 * columns rather than relations on purpose - the merged puzzles are deleted moments
 * later, and a foreign key would either block the delete or null the audit trail.
 */
#[Entity]
#[Index(fields: ['mergeRequestId'], name: 'idx_puzzle_merge_audit_merge_request')]
#[Index(fields: ['performedAt'], name: 'idx_puzzle_merge_audit_performed_at')]
class PuzzleMergeAudit
{
    public function __construct(
        #[Id]
        #[Immutable]
        #[Column(type: UuidType::NAME, unique: true)]
        public UuidInterface $id,
        #[Immutable]
        #[Column(type: UuidType::NAME)]
        public UuidInterface $mergeRequestId,
        #[Immutable]
        #[Column(type: UuidType::NAME)]
        public UuidInterface $survivorPuzzleId,
        #[Immutable]
        #[Column]
        public DateTimeImmutable $performedAt,
        // Reviewer credited with the merge (nullable for audit trail - SET NULL when player deleted)
        #[Immutable]
        #[ManyToOne]
        #[JoinColumn(nullable: true, onDelete: 'SET NULL')]
        public null|Player $performedBy,
        #[Immutable]
        #[Column(type: 'string', enumType: MergeDecisionSource::class)]
        public MergeDecisionSource $decisionSource,
        /**
         * Everything destroyed or rewritten by the merge: the full row of every
         * puzzle involved, plus the inventory of records migrated and removed.
         *
         * @var array<string, mixed>
         */
        #[Immutable]
        #[Column(type: Types::JSON)]
        public array $snapshotBefore,
        /**
         * The survivor puzzle as it stands after the merge.
         *
         * @var array<string, mixed>
         */
        #[Immutable]
        #[Column(type: Types::JSON)]
        public array $snapshotAfter,
        // Why the merge was approved - free text, for automated reviews especially
        #[Immutable]
        #[Column(type: 'text', nullable: true)]
        public null|string $decisionNote = null,
        // How sure the reviewer was; lets low-confidence merges be re-examined first
        #[Immutable]
        #[Column(type: 'string', length: 16, nullable: true, enumType: MergeDecisionConfidence::class)]
        public null|MergeDecisionConfidence $decisionConfidence = null,
    ) {
    }
}
