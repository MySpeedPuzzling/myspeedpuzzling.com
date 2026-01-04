<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Entity;

use DateTimeImmutable;
use Doctrine\ORM\Mapping\Column;
use Doctrine\ORM\Mapping\Entity;
use Doctrine\ORM\Mapping\Id;
use Doctrine\ORM\Mapping\JoinColumn;
use Doctrine\ORM\Mapping\ManyToOne;
use JetBrains\PhpStorm\Immutable;
use Ramsey\Uuid\Doctrine\UuidType;
use Ramsey\Uuid\UuidInterface;
use SpeedPuzzling\Web\Value\PuzzleReportStatus;

#[Entity]
class PuzzleChangeRequest
{
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

    public function __construct(
        #[Id]
        #[Immutable]
        #[Column(type: UuidType::NAME, unique: true)]
        public UuidInterface $id,
        #[Immutable]
        #[ManyToOne]
        #[JoinColumn(nullable: false, onDelete: 'CASCADE')]
        public Puzzle $puzzle,
        #[Immutable]
        #[ManyToOne]
        #[JoinColumn(nullable: false, onDelete: 'CASCADE')]
        public Player $reporter,
        #[Immutable]
        #[Column]
        public DateTimeImmutable $submittedAt,
        // Proposed changes (nullable - only set if user proposes change)
        #[Immutable]
        #[Column(nullable: true)]
        public null|string $proposedName = null,
        #[Immutable]
        #[ManyToOne]
        #[JoinColumn(nullable: true, onDelete: 'SET NULL')]
        public null|Manufacturer $proposedManufacturer = null,
        #[Immutable]
        #[Column(nullable: true)]
        public null|int $proposedPiecesCount = null,
        #[Immutable]
        #[Column(nullable: true)]
        public null|string $proposedEan = null,
        #[Immutable]
        #[Column(nullable: true)]
        public null|string $proposedIdentificationNumber = null,
        #[Immutable]
        #[Column(nullable: true)]
        public null|string $proposedImage = null,
        // Original values (snapshot at time of request for audit trail)
        #[Immutable]
        #[Column]
        public string $originalName = '',
        #[Immutable]
        #[Column(type: UuidType::NAME, nullable: true)]
        public null|UuidInterface $originalManufacturerId = null,
        #[Immutable]
        #[Column]
        public int $originalPiecesCount = 0,
        #[Immutable]
        #[Column(nullable: true)]
        public null|string $originalEan = null,
        #[Immutable]
        #[Column(nullable: true)]
        public null|string $originalIdentificationNumber = null,
        #[Immutable]
        #[Column(nullable: true)]
        public null|string $originalImage = null,
    ) {
    }

    public function approve(Player $reviewedBy, DateTimeImmutable $reviewedAt): void
    {
        $this->status = PuzzleReportStatus::Approved;
        $this->reviewedBy = $reviewedBy;
        $this->reviewedAt = $reviewedAt;
    }

    public function reject(Player $reviewedBy, DateTimeImmutable $reviewedAt, string $reason): void
    {
        $this->status = PuzzleReportStatus::Rejected;
        $this->reviewedBy = $reviewedBy;
        $this->reviewedAt = $reviewedAt;
        $this->rejectionReason = $reason;
    }

    public function hasProposedChanges(): bool
    {
        return $this->proposedName !== null
            || $this->proposedManufacturer !== null
            || $this->proposedPiecesCount !== null
            || $this->proposedEan !== null
            || $this->proposedIdentificationNumber !== null
            || $this->proposedImage !== null;
    }
}
