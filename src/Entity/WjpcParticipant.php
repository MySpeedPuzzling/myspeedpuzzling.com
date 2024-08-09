<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Entity;

use Doctrine\ORM\Mapping\Column;
use Doctrine\ORM\Mapping\Entity;
use Doctrine\ORM\Mapping\Id;
use Doctrine\ORM\Mapping\ManyToOne;
use JetBrains\PhpStorm\Immutable;
use Ramsey\Uuid\Doctrine\UuidType;
use Ramsey\Uuid\UuidInterface;

#[Entity]
class WjpcParticipant
{
    public function __construct(
        #[Id]
        #[Immutable]
        #[Column(type: UuidType::NAME, unique: true)]
        public UuidInterface $id,

        #[Immutable]
        #[Column]
        public string $name,

        #[Immutable]
        #[Column]
        public string $country,

        #[Immutable]
        #[ManyToOne]
        public null|Player $player = null,

        #[Immutable]
        #[Column(nullable: true)]
        public null|int $year2023Rank = null,

        #[Immutable]
        #[Column(nullable: true)]
        public null|int $year2022Rank = null,
    ) {
    }
}
