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
class PlayerPuzzleCollection
{
    public function __construct(
        #[Id]
        #[Immutable]
        #[Column(type: UuidType::NAME, unique: true)]
        public UuidInterface $id,

        #[ManyToOne]
        #[JoinColumn(nullable: false)]
        #[Immutable]
        public Player $player,

        #[ManyToOne]
        #[JoinColumn(nullable: false)]
        #[Immutable]
        public Puzzle $puzzle,

        #[ManyToOne]
        #[JoinColumn(nullable: true)]
        public null|CollectionFolder $folder = null,

        #[Column(type: Types::DATETIME_IMMUTABLE)]
        #[Immutable]
        public DateTimeImmutable $addedAt = new DateTimeImmutable(),

        #[Column(nullable: true)]
        public null|string $notes = null,

        #[ManyToOne]
        #[JoinColumn(nullable: true)]
        public null|Player $lentTo = null,

        #[Column(type: Types::DATE_IMMUTABLE, nullable: true)]
        public null|DateTimeImmutable $lentAt = null,
    ) {
    }

    public function moveToFolder(null|CollectionFolder $folder): void
    {
        if ($folder !== null && $folder->player->id->toString() !== $this->player->id->toString()) {
            throw new \DomainException('Cannot move puzzle to folder belonging to different player');
        }

        $this->folder = $folder;
    }

    public function updateNotes(null|string $notes): void
    {
        $this->notes = $notes;
    }

    public function lendTo(Player $player): void
    {
        if ($this->lentTo !== null) {
            throw new \DomainException('Puzzle is already lent to someone');
        }

        $this->lentTo = $player;
        $this->lentAt = new DateTimeImmutable();
    }

    public function returnFromLend(): void
    {
        if ($this->lentTo === null) {
            throw new \DomainException('Puzzle is not currently lent');
        }

        $this->lentTo = null;
        $this->lentAt = null;
    }

    public function isLent(): bool
    {
        return $this->lentTo !== null;
    }
}
