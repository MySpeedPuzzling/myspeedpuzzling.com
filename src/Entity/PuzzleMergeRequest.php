<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Entity;

use DateTimeImmutable;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping\Column;
use Doctrine\ORM\Mapping\Entity;
use Doctrine\ORM\Mapping\Id;
use Doctrine\ORM\Mapping\JoinColumn;
use Doctrine\ORM\Mapping\ManyToOne;
use JetBrains\PhpStorm\Immutable;
use Ramsey\Uuid\Doctrine\UuidType;
use Ramsey\Uuid\UuidInterface;
use SpeedPuzzling\Web\Events\PuzzleMergeApproved;
use SpeedPuzzling\Web\Value\PuzzleReportStatus;

#[Entity]
class PuzzleMergeRequest implements EntityWithEvents
{
    use HasEvents;

    #[Immutable(Immutable::PRIVATE_WRITE_SCOPE)]
    #[Column(type: 'string', enumType: PuzzleReportStatus::class)]
    public PuzzleReportStatus $status = PuzzleReportStatus::Pending;

    #[Immutable(Immutable::PRIVATE_WRITE_SCOPE)]
    #[Column(nullable: true)]
    public null|DateTimeImmutable $reviewedAt = null;

    #[Immutable(Immutable::PRIVATE_WRITE_SCOPE)]
    #[ManyToOne]
    #[JoinColumn(nullable: true, onDelete: 'SET NULL')]
    public null|Player $reviewedBy = null;

    #[Immutable(Immutable::PRIVATE_WRITE_SCOPE)]
    #[Column(type: 'text', nullable: true)]
    public null|string $rejectionReason = null;

    // The puzzle that survived the merge (set after approval)
    #[Immutable(Immutable::PRIVATE_WRITE_SCOPE)]
    #[Column(type: UuidType::NAME, nullable: true)]
    public null|UuidInterface $survivorPuzzleId = null;

    // All puzzle IDs that were merged (for audit trail)
    /**
     * @var array<string>
     */
    #[Immutable(Immutable::PRIVATE_WRITE_SCOPE)]
    #[Column(type: Types::JSON, options: ['default' => '[]'])]
    public array $mergedPuzzleIds = [];

    // Store source puzzle name for display even after puzzle is deleted
    #[Immutable(Immutable::PRIVATE_WRITE_SCOPE)]
    #[Column(type: 'string', length: 255, nullable: true)]
    public null|string $sourcePuzzleName = null;

    public function __construct(
        #[Id]
        #[Immutable]
        #[Column(type: UuidType::NAME, unique: true)]
        public UuidInterface $id,
        // The puzzle from which the report was initiated (nullable for audit trail - SET NULL when puzzle deleted)
        #[Immutable(Immutable::PRIVATE_WRITE_SCOPE)]
        #[ManyToOne]
        #[JoinColumn(nullable: true, onDelete: 'SET NULL')]
        public null|Puzzle $sourcePuzzle,
        // Reporter player (nullable for audit trail - SET NULL when player deleted)
        #[Immutable(Immutable::PRIVATE_WRITE_SCOPE)]
        #[ManyToOne]
        #[JoinColumn(nullable: true, onDelete: 'SET NULL')]
        public null|Player $reporter,
        #[Immutable]
        #[Column]
        public DateTimeImmutable $submittedAt,
        // All puzzle IDs reported as duplicates (including source, max 5)
        /**
         * @var array<string>
         */
        #[Immutable]
        #[Column(type: Types::JSON)]
        public array $reportedDuplicatePuzzleIds = [],
    ) {
        // Store puzzle name for display even after puzzle is deleted
        $this->sourcePuzzleName = $sourcePuzzle?->name;
    }

    /**
     * @param array<string> $mergedPuzzleIds
     */
    public function approve(
        Player $reviewedBy,
        DateTimeImmutable $reviewedAt,
        UuidInterface $survivorPuzzleId,
        array $mergedPuzzleIds,
    ): void {
        $this->status = PuzzleReportStatus::Approved;
        $this->reviewedBy = $reviewedBy;
        $this->reviewedAt = $reviewedAt;
        $this->survivorPuzzleId = $survivorPuzzleId;
        $this->mergedPuzzleIds = $mergedPuzzleIds;

        $this->recordThat(new PuzzleMergeApproved(
            mergeRequestId: $this->id,
            survivorPuzzleId: $survivorPuzzleId,
            puzzleIdsToDelete: $mergedPuzzleIds,
        ));
    }

    public function reject(Player $reviewedBy, DateTimeImmutable $reviewedAt, string $reason): void
    {
        $this->status = PuzzleReportStatus::Rejected;
        $this->reviewedBy = $reviewedBy;
        $this->reviewedAt = $reviewedAt;
        $this->rejectionReason = $reason;
    }

    public function getDuplicateCount(): int
    {
        return count($this->reportedDuplicatePuzzleIds);
    }
}
