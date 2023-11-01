<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Entity;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping\Column;
use Doctrine\ORM\Mapping\Entity;
use Doctrine\ORM\Mapping\Id;
use Doctrine\ORM\Mapping\JoinColumn;
use Doctrine\ORM\Mapping\ManyToOne;
use Ramsey\Uuid\Doctrine\UuidType;
use Ramsey\Uuid\UuidInterface;

#[Entity]
class PuzzleSolvingTime
{
    public function __construct(
        #[Id]
        #[Column(type: UuidType::NAME, unique: true)]
        readonly public UuidInterface $id,

        #[Column(type: Types::INTEGER)]
        public int $secondsToSolve,

        #[Column(type: Types::INTEGER)]
        public int $playersCount,

        #[ManyToOne]
        #[JoinColumn(nullable: false)]
        public User $addedByUser,

        #[ManyToOne]
        #[JoinColumn(nullable: false)]
        public Puzzle $puzzle,

        #[Column(nullable: true)]
        public null|string $comment = null,
    ) {
    }
}
