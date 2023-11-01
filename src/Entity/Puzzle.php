<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Entity;

use Doctrine\ORM\Mapping\Column;
use Doctrine\ORM\Mapping\Entity;
use Doctrine\ORM\Mapping\Id;
use Doctrine\ORM\Mapping\ManyToOne;
use Ramsey\Uuid\Doctrine\UuidType;
use Ramsey\Uuid\UuidInterface;

#[Entity]
class Puzzle
{
    public function __construct(
        #[Id]
        #[Column(type: UuidType::NAME, unique: true)]
        readonly public UuidInterface $id,

        #[Column]
        public int $piecesCount,

        #[Column]
        public string $name,

        #[Column]
        public bool $approved,

        #[ManyToOne]
        public null|Manufacturer $manufacturer = null,

        #[Column(nullable: true)]
        public null|string $alternativeName = null,

        #[ManyToOne]
        public null|User $addedByUser = null,

        #[Column(nullable: true)]
        public null|string $identificationNumber = null,

        #[Column(nullable: true)]
        public null|string $ean = null,
    ) {
    }
}
