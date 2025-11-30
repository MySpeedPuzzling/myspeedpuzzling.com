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
use SpeedPuzzling\Web\Doctrine\PuzzlersGroupDoctrineType;
use SpeedPuzzling\Web\Events\PuzzleTracked;
use SpeedPuzzling\Web\Value\PuzzlersGroup;

#[Entity]
#[Index(columns: ["tracked_at"])]
class PuzzleTracking implements EntityWithEvents
{
    use HasEvents;

    public function __construct(
        #[Id]
        #[Immutable]
        #[Column(type: UuidType::NAME, unique: true)]
        public UuidInterface $id,
        #[ManyToOne]
        #[JoinColumn(nullable: false)]
        public Player $player,
        #[ManyToOne]
        #[JoinColumn(nullable: false, onDelete: 'CASCADE')]
        public Puzzle $puzzle,
        #[Column(type: Types::DATETIME_IMMUTABLE)]
        public DateTimeImmutable $trackedAt,
        #[Column(type: PuzzlersGroupDoctrineType::NAME, nullable: true)]
        public null|PuzzlersGroup $team,
        #[Column(type: Types::DATETIME_IMMUTABLE)]
        public DateTimeImmutable $finishedAt,
        #[Column(type: Types::TEXT, nullable: true)]
        public null|string $comment,
        #[Column(nullable: true)]
        public null|string $finishedPuzzlePhoto,
    ) {
        $this->recordThat(
            new PuzzleTracked($this->id),
        );
    }
}
