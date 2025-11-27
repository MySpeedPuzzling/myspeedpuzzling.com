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

#[Entity]
#[UniqueConstraint(columns: ['owner_player_id', 'puzzle_id'])]
class LentPuzzle
{
    public function __construct(
        #[Id]
        #[Immutable]
        #[Column(type: UuidType::NAME, unique: true)]
        public UuidInterface $id,
        #[Immutable]
        #[ManyToOne]
        #[JoinColumn(nullable: false)]
        public Puzzle $puzzle,
        #[Immutable]
        #[ManyToOne]
        #[JoinColumn(nullable: true)]
        public null|Player $ownerPlayer,
        #[Immutable]
        #[Column(type: Types::STRING, nullable: true, length: 200)]
        public null|string $ownerName,
        #[Immutable(Immutable::PRIVATE_WRITE_SCOPE)]
        #[ManyToOne]
        #[JoinColumn(nullable: true)]
        public null|Player $currentHolderPlayer,
        #[Immutable(Immutable::PRIVATE_WRITE_SCOPE)]
        #[Column(type: Types::STRING, nullable: true, length: 200)]
        public null|string $currentHolderName,
        #[Immutable]
        #[Column(type: Types::DATETIME_IMMUTABLE)]
        public DateTimeImmutable $lentAt,
        #[Immutable(Immutable::PRIVATE_WRITE_SCOPE)]
        #[Column(type: Types::TEXT, nullable: true, length: 500)]
        public null|string $notes = null,
    ) {
    }

    public function changeCurrentHolder(null|Player $player, null|string $name): void
    {
        $this->currentHolderPlayer = $player;
        $this->currentHolderName = $player === null ? $name : null;
    }

    public function changeNotes(null|string $notes): void
    {
        $this->notes = $notes;
    }
}
