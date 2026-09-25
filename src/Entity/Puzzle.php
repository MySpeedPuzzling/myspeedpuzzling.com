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

#[Entity]
#[Index(columns: ['pieces_count'])]
#[Index(columns: ['identification_number'])]
#[Index(columns: ['ean'])]
class Puzzle
{
    public function __construct(
        #[Id]
        #[Immutable]
        #[Column(type: UuidType::NAME, unique: true)]
        public UuidInterface $id,
        #[Column]
        public int $piecesCount,
        #[Column]
        public string $name,
        #[Column]
        public bool $approved,
        #[Column(nullable: true)]
        public null|string $image = null,
        #[Column(nullable: true)]
        public null|float $imageRatio = null,
        #[ManyToOne]
        public null|Manufacturer $manufacturer = null,
        #[Column(nullable: true)]
        public null|string $alternativeName = null,
        #[Immutable]
        #[ManyToOne]
        public null|Player $addedByUser = null,
        #[Immutable]
        #[Column(nullable: true)]
        public null|DateTimeImmutable $addedAt = null,
        #[Immutable(Immutable::PRIVATE_WRITE_SCOPE)]
        #[Column(nullable: true)]
        public null|string $identificationNumber = null,
        #[Immutable(Immutable::PRIVATE_WRITE_SCOPE)]
        #[Column(nullable: true)]
        public null|string $ean = null,
        #[Immutable(Immutable::PRIVATE_WRITE_SCOPE)]
        #[Column]
        public bool $isAvailable = false,
        #[Column(nullable: true)]
        public null|DateTimeImmutable $hideImageUntil = null,
        #[Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
        public null|DateTimeImmutable $hideUntil = null,
        // Who approved the puzzle in the approval queue, and when. Both stay null for
        // puzzles approved before the queue existed (by SQL) or added approved.
        #[Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
        public null|DateTimeImmutable $approvedAt = null,
        #[ManyToOne]
        #[JoinColumn(onDelete: 'SET NULL')]
        public null|Player $approvedBy = null,
    ) {
    }

    public function approve(Player $approvedBy, DateTimeImmutable $approvedAt): void
    {
        $this->approved = true;
        $this->approvedBy = $approvedBy;
        $this->approvedAt = $approvedAt;
    }

    public function updateProductIdentifiers(null|string $ean, null|string $identificationNumber): void
    {
        $this->ean = $ean;
        $this->identificationNumber = $identificationNumber;
    }
}
