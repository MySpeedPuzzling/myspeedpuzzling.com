<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Entity;

use DateTimeImmutable;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping\Column;
use Doctrine\ORM\Mapping\Entity;
use Doctrine\ORM\Mapping\Id;
use Doctrine\ORM\Mapping\ManyToOne;
use JetBrains\PhpStorm\Immutable;
use Ramsey\Uuid\Doctrine\UuidType;
use Ramsey\Uuid\UuidInterface;
use SpeedPuzzling\Web\Events\PuzzleCollectionCreated;
use SpeedPuzzling\Web\Events\PuzzleCollectionDeleted;
use SpeedPuzzling\Web\Events\PuzzleCollectionUpdated;
use SpeedPuzzling\Web\Value\CollectionType;

#[Entity]
class PuzzleCollection implements EntityWithEvents
{
    use HasEvents;

    public const SYSTEM_COMPLETED = 'completed';
    public const SYSTEM_WISHLIST = 'wishlist';
    public const SYSTEM_TODO = 'todolist';
    public const SYSTEM_BORROWED_TO = 'borrowed_to';
    public const SYSTEM_BORROWED_FROM = 'borrowed_from';
    public const SYSTEM_FOR_SALE = 'for_sale';
    public const SYSTEM_MY_COLLECTION = 'my_collection';

    #[Immutable(Immutable::PRIVATE_WRITE_SCOPE)]
    #[Column(type: Types::TEXT, nullable: true)]
    public null|string $description = null;

    #[Immutable(Immutable::PRIVATE_WRITE_SCOPE)]
    #[Column(type: Types::BOOLEAN)]
    public bool $isPublic = true;

    #[Immutable(Immutable::PRIVATE_WRITE_SCOPE)]
    #[Column(type: Types::STRING, nullable: true)]
    public null|string $systemType = null;

    #[Immutable(Immutable::PRIVATE_WRITE_SCOPE)]
    #[Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    public null|DateTimeImmutable $updatedAt = null;

    public function __construct(
        #[Id]
        #[Immutable]
        #[Column(type: UuidType::NAME, unique: true)]
        public UuidInterface $id,
        #[Immutable]
        #[ManyToOne]
        public Player $player,
        #[Immutable(Immutable::PRIVATE_WRITE_SCOPE)]
        #[Column(nullable: true)]
        public null|string $name,
        #[Immutable]
        #[Column(type: Types::DATETIME_IMMUTABLE)]
        public DateTimeImmutable $createdAt,
    ) {
        if ($this->name !== null) {
            $this->recordThat(new PuzzleCollectionCreated($this->id, $this->player->id, $this->name));
        }
    }

    public function update(string $name, null|string $description, bool $isPublic): void
    {
        $this->name = $name;
        $this->description = $description;
        $this->isPublic = $isPublic;
        $this->updatedAt = new DateTimeImmutable();

        $this->recordThat(new PuzzleCollectionUpdated($this->id, $this->player->id, $name));
    }

    public function updateVisibility(bool $isPublic): void
    {
        $this->isPublic = $isPublic;
        $this->updatedAt = new DateTimeImmutable();
    }

    public function delete(): void
    {
        $this->recordThat(new PuzzleCollectionDeleted($this->id, $this->player->id));
    }

    public function isSystemCollection(): bool
    {
        return $this->systemType !== null;
    }

    public function isCustomCollection(): bool
    {
        return $this->systemType === null && $this->name !== null;
    }

    public function isRootCollection(): bool
    {
        return $this->systemType === null && $this->name === null;
    }

    public function canBeDeleted(): bool
    {
        return $this->isCustomCollection();
    }

    public function canBeRenamed(): bool
    {
        return $this->isCustomCollection();
    }
}