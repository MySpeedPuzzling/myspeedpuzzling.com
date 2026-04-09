<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Entity;

use Doctrine\ORM\Mapping\Column;
use Doctrine\ORM\Mapping\Entity;
use Doctrine\ORM\Mapping\Id;
use Doctrine\ORM\Mapping\JoinColumn;
use Doctrine\ORM\Mapping\ManyToOne;
use JetBrains\PhpStorm\Immutable;
use Ramsey\Uuid\Doctrine\UuidType;
use Ramsey\Uuid\UuidInterface;

#[Entity]
class CompetitionTeam
{
    public function __construct(
        #[Id]
        #[Immutable]
        #[Column(type: UuidType::NAME, unique: true)]
        public UuidInterface $id,
        #[ManyToOne]
        #[JoinColumn(nullable: false)]
        public CompetitionRound $round,
        #[Column(nullable: true)]
        public null|string $name = null,
    ) {
    }

    public function rename(null|string $name): void
    {
        $this->name = $name;
    }
}
