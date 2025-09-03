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
use SpeedPuzzling\Web\Events\PuzzleAddedToCollection;
use SpeedPuzzling\Web\Events\PuzzleRemovedFromCollection;

#[Entity]
class PuzzleCollectionItem implements EntityWithEvents
{
    use HasEvents;

    #[Immutable(Immutable::PRIVATE_WRITE_SCOPE)]
    #[Column(type: Types::TEXT, nullable: true)]
    public null|string $comment = null;

    #[Immutable(Immutable::PRIVATE_WRITE_SCOPE)]
    #[Column(type: Types::DECIMAL, precision: 10, scale: 2, nullable: true)]
    public null|string $price = null;

    #[Immutable(Immutable::PRIVATE_WRITE_SCOPE)]
    #[Column(nullable: true)]
    public null|string $condition = null;

    public function __construct(
        #[Id]
        #[Immutable]
        #[Column(type: UuidType::NAME, unique: true)]
        public UuidInterface $id,
        #[Immutable]
        #[ManyToOne]
        public null|PuzzleCollection $collection,
        #[Immutable]
        #[ManyToOne]
        #[JoinColumn(nullable: false)]
        public Puzzle $puzzle,
        #[Immutable]
        #[ManyToOne]
        #[JoinColumn(nullable: false)]
        public Player $player,
        #[Immutable]
        #[Column(type: Types::DATETIME_IMMUTABLE)]
        public DateTimeImmutable $addedAt,
    ) {
        $this->recordThat(new PuzzleAddedToCollection(
            $this->puzzle->id,
            $this->collection?->id,
            $this->player->id,
            $this->collection?->systemType
        ));
    }

    public function updateComment(null|string $comment): void
    {
        $this->comment = $comment;
    }

    public function updateForSale(null|string $price, null|string $condition): void
    {
        $this->price = $price;
        $this->condition = $condition;
    }

    public function remove(): void
    {
        $this->recordThat(new PuzzleRemovedFromCollection(
            $this->puzzle->id,
            $this->collection?->id,
            $this->player->id,
            $this->collection?->systemType
        ));
    }
}
