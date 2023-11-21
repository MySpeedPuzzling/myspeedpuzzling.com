<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Entity;

use DateTimeImmutable;
use Doctrine\ORM\Mapping\Column;
use Doctrine\ORM\Mapping\Entity;
use Doctrine\ORM\Mapping\Id;
use Doctrine\ORM\Mapping\JoinColumn;
use Doctrine\ORM\Mapping\ManyToOne;
use Ramsey\Uuid\Doctrine\UuidType;
use Ramsey\Uuid\UuidInterface;

#[Entity]
class Manufacturer
{
    public function __construct(
        #[Id]
        #[Column(type: UuidType::NAME, unique: true)]
        readonly public UuidInterface $id,

        #[Column]
        public string $name,

        #[Column]
        public bool $approved,

        #[ManyToOne]
        #[JoinColumn(nullable: true)]
        public null|Player $addedByUser = null,

        #[Column(nullable: true)]
        public null|DateTimeImmutable $addedAt = null,
    ) {
    }
}
