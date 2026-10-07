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
use SpeedPuzzling\Web\Value\PuzzleReportOutdatedReason;
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

    // Why it was closed as outdated (PuzzleReportStatus::Outdated)
    #[Immutable(Immutable::PRIVATE_WRITE_SCOPE)]
    #[Column(type: 'string', nullable: true, enumType: PuzzleReportOutdatedReason::class)]
    public null|PuzzleReportOutdatedReason $outdatedReason = null;

    // The merge that left nothing to merge here - null when the daily check found it (OutdatedPuzzleRequests)
    #[Immutable(Immutable::PRIVATE_WRITE_SCOPE)]
    #[Column(type: UuidType::NAME, nullable: true)]
    public null|UuidInterface $outdatedByMergeRequestId = null;

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
        // What the reporter said each reported puzzle's name is in ("this record is the Czech box"), optional:
        // puzzle id => base language. The merge review starts from it (docs/features/puzzle-names/README.md)
        /**
         * @var array<string, string>
         */
        #[Immutable]
        #[Column(type: Types::JSONB, options: ['default' => '{}'])]
        public array $reportedNameLanguages = [],
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

    /**
     * Other merges (or deletions) left fewer than two of its puzzles - nothing to merge, nobody reviewed it. The one
     * puzzle left, if any, is what its puzzles became: shown like an approved merge's survivor.
     */
    public function markOutdated(
        PuzzleReportOutdatedReason $reason,
        DateTimeImmutable $at,
        null|UuidInterface $currentPuzzleId,
        null|UuidInterface $byMergeRequestId,
    ): void {
        $this->status = PuzzleReportStatus::Outdated;
        $this->reviewedAt = $at;
        $this->outdatedReason = $reason;
        $this->survivorPuzzleId = $currentPuzzleId;
        $this->outdatedByMergeRequestId = $byMergeRequestId;
    }

    public function clearSourcePuzzleReference(): void
    {
        $this->sourcePuzzle = null;
    }

    public function getDuplicateCount(): int
    {
        return count($this->reportedDuplicatePuzzleIds);
    }
}
