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
use SpeedPuzzling\Web\Doctrine\PuzzlersGroupDoctrineType;
use SpeedPuzzling\Web\Value\PuzzlersGroup;

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

        #[Column(type: PuzzlersGroupDoctrineType::NAME, nullable: true)]
        public null|PuzzlersGroup $team,

        #[Column(type: Types::DATETIME_IMMUTABLE)]
        public DateTimeImmutable $finishedAt,

        #[Column(nullable: true)]
        public null|string $comment,

        #[Column(nullable: true)]
        public null|string $finishedPuzzlePhoto,
    ) {
    }

    public function modify(
        int $seconds,
        null|string $comment,
        null|PuzzlersGroup $puzzlersGroup,
        DateTimeImmutable $finishedAt,
    ): void
    {
        $this->secondsToSolve = $seconds;
        $this->comment = $comment;
        $this->team = $puzzlersGroup;
        $this->finishedAt = $finishedAt;
    }
}
