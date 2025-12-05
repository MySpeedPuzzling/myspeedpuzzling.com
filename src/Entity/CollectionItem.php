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
use Doctrine\ORM\Mapping\UniqueConstraint;
use JetBrains\PhpStorm\Immutable;
use Ramsey\Uuid\Doctrine\UuidType;
use Ramsey\Uuid\UuidInterface;
use SpeedPuzzling\Web\Events\PuzzleAddedToCollection;

#[Entity]
#[UniqueConstraint(columns: ['collection_id', 'player_id', 'puzzle_id'])]
class CollectionItem implements EntityWithEvents
{
    use HasEvents;

    public function __construct(
        #[Id]
        #[Immutable]
        #[Column(type: UuidType::NAME, unique: true)]
        public UuidInterface $id,
        #[Immutable]
        #[ManyToOne]
        #[JoinColumn(nullable: true, onDelete: 'CASCADE')]
        public null|Collection $collection,
        #[Immutable]
        #[ManyToOne]
        #[JoinColumn(nullable: false)]
        public Player $player,
        #[Immutable]
        #[ManyToOne]
        #[JoinColumn(nullable: false)]
        public Puzzle $puzzle,
        #[Immutable(Immutable::PRIVATE_WRITE_SCOPE)]
        #[Column(type: Types::TEXT, length: 500, nullable: true)]
        public null|string $comment,
        #[Immutable]
        #[Column(type: Types::DATETIME_IMMUTABLE)]
        public DateTimeImmutable $addedAt,
    ) {
        $this->recordThat(new PuzzleAddedToCollection(
            $this->id,
            $this->player->id->toString(),
            $this->puzzle->id->toString(),
        ));
    }

    public function changeComment(null|string $comment): void
    {
        $this->comment = $comment;
    }
}
