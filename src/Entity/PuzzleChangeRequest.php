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
use SpeedPuzzling\Web\Value\PuzzleNames;
use SpeedPuzzling\Web\Value\PuzzleNamesDiff;
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
        #[Immutable(Immutable::PRIVATE_WRITE_SCOPE)]
        #[ManyToOne]
        #[JoinColumn(nullable: true, onDelete: 'SET NULL')]
        public null|Manufacturer $proposedManufacturer = null,
        #[Immutable]
        #[Column(nullable: true)]
        public null|int $proposedPiecesCount = null,
        // The code lists as proposed, canonical (EanList::toStored(), BrandCodeList::toStored()): null = not proposed,
        // '' = every code removed. Requests filed before the lists hold whatever the form had, changed or not
        #[Immutable]
        #[Column(nullable: true)]
        public null|string $proposedEan = null,
        #[Immutable]
        #[Column(nullable: true)]
        public null|string $proposedIdentificationNumber = null,
        #[Immutable]
        #[Column(nullable: true)]
        public null|string $proposedImage = null,
        #[Immutable]
        #[Column(nullable: true)]
        public null|float $proposedImageRatio = null,
        /**
         * The other names as proposed - the whole list, [{name, language}]; null = the names are not part of the
         * proposal. Approval applies it as a diff against originalAlternativeNames, never as a replacement.
         *
         * @var null|list<array{name: string, language: null|string}>
         */
        #[Immutable]
        #[Column(type: Types::JSONB, nullable: true)]
        public null|array $proposedAlternativeNames = null,
        // The main title's language as proposed (null = English or not known) - proposed only together with the
        // other names (proposedAlternativeNames not null)
        #[Immutable]
        #[Column(length: 16, nullable: true)]
        public null|string $proposedNameLanguage = null,
        // Original values (snapshot at time of request for audit trail)
        #[Immutable]
        #[Column]
        public string $originalName = '',
        #[Immutable(Immutable::PRIVATE_WRITE_SCOPE)]
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
        /**
         * The other names when proposed - null on requests older than the names list
         *
         * @var null|list<array{name: string, language: null|string}>
         */
        #[Immutable]
        #[Column(type: Types::JSONB, nullable: true)]
        public null|array $originalAlternativeNames = null,
        #[Immutable]
        #[Column(length: 16, nullable: true)]
        public null|string $originalNameLanguage = null,
        // The name of the brand this proposal created - the player typed a brand no brand matched (ManufacturerResolver);
        // null when it proposes an existing brand. Kept when the brand is gone: an unused one is deleted on review
        // (ChangeRequestCreatedBrandSettler), the history still shows what was proposed
        #[Immutable(Immutable::PRIVATE_WRITE_SCOPE)]
        #[Column(nullable: true)]
        public null|string $createdManufacturerName = null,
    ) {
    }

    /**
     * What the proposal does to the other names - empty when it proposes none.
     */
    public function proposedNamesDiff(): PuzzleNamesDiff
    {
        if ($this->proposedAlternativeNames === null) {
            return new PuzzleNamesDiff([], [], []);
        }

        return PuzzleNames::fromArray($this->proposedAlternativeNames)
            ->diff(PuzzleNames::fromArray($this->originalAlternativeNames ?? []));
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
            || $this->proposedImage !== null
            || $this->proposedAlternativeNames !== null;
    }

    /**
     * The proposed brand was merged into another one - keep proposing the same
     * brand under its surviving record instead of losing the proposal.
     */
    public function proposedManufacturerMergedInto(Manufacturer $into): void
    {
        $this->proposedManufacturer = $into;
        // It proposes an existing brand now - the one it created is gone
        $this->createdManufacturerName = null;
    }

    /**
     * The brand this proposal created was not used and is deleted - the proposal keeps its name only.
     */
    public function createdManufacturerDeleted(): void
    {
        $this->proposedManufacturer = null;
    }

    /**
     * The brand the puzzle had when this was submitted was merged into another one - it is the same
     * brand under its surviving record. Left on the deleted id, the review would show the original
     * brand as "not set" and a brand change that the merge already made.
     */
    public function originalManufacturerMergedInto(Manufacturer $into): void
    {
        $this->originalManufacturerId = $into->id;
    }
}
