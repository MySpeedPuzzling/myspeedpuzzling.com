<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Entity;

use DateTimeImmutable;
use Doctrine\ORM\Mapping\Column;
use Doctrine\ORM\Mapping\Entity;
use Doctrine\ORM\Mapping\Id;
use Doctrine\ORM\Mapping\ManyToOne;
use JetBrains\PhpStorm\Immutable;
use Ramsey\Uuid\Doctrine\UuidType;
use Ramsey\Uuid\UuidInterface;

#[Entity]
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

        #[ManyToOne]
        public null|Manufacturer $manufacturer = null,

        #[Column(nullable: true)]
        public null|string $alternativeName = null,

        #[ManyToOne]
        public null|Player $addedByUser = null,

        #[Column(nullable: true)]
        public null|DateTimeImmutable $addedAt = null,

        #[Column(nullable: true)]
        public null|string $identificationNumber = null,

        #[Column(nullable: true)]
        public null|string $ean = null,

        #[Column]
        public bool $isAvailable = false,

        #[ManyToOne]
        public null|CompetitionRound $competitionRound = null,

        #[Column(nullable: true)]
        public null|int $missingPieces = null,

        #[Column(nullable: true)]
        public null|bool $qualified = null,
    ) {
    }
}
