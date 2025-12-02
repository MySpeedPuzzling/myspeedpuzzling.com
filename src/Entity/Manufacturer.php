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
class Manufacturer
{
    public function __construct(
        #[Id]
        #[Immutable]
        #[Column(type: UuidType::NAME, unique: true)]
        public UuidInterface $id,
        #[Column]
        public string $name,
        #[Column]
        public bool $approved,
        #[ManyToOne]
        public null|Player $addedByUser = null,
        #[Column(nullable: true)]
        public null|DateTimeImmutable $addedAt = null,
        #[Column(nullable: true)]
        public null|string $logo = null,
    ) {
    }
}
