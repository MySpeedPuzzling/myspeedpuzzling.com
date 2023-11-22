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

#[Entity]
class PuzzleSolvingTime
{
    public function __construct(
        #[Id]
        #[Immutable]
        #[Column(type: UuidType::NAME, unique: true)]
        public UuidInterface $id,

        #[Column(type: Types::INTEGER, nullable: true)]
        public null|int $secondsToSolve,

        #[Column(type: Types::INTEGER)]
        public int $playersCount,

        #[ManyToOne]
        #[JoinColumn(nullable: false)]
        public Player $player,

        #[ManyToOne]
        #[JoinColumn(nullable: false)]
        public Puzzle $puzzle,

        #[Column(type: Types::DATETIME_IMMUTABLE)]
        public DateTimeImmutable $trackedAt,

        #[Column(type: Types::BOOLEAN)]
        public bool $verified,

        #[Column(nullable: true)]
        public null|string $comment = null,

        #[Column(nullable: true)]
        public null|string $groupName = null,

        #[Column(nullable: true)]
        public null|string $finishedPuzzlePhoto = null,
    ) {
    }

    public function modify(
        int $seconds,
        int $playersCount,
        null|string $comment,
    ): void
    {
        $this->secondsToSolve = $seconds;
        $this->playersCount = $playersCount;
        $this->comment = $comment;
    }
}
