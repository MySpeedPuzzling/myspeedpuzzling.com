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
class TableSpot
{
    public function __construct(
        #[Id]
        #[Immutable]
        #[Column(type: UuidType::NAME, unique: true)]
        public UuidInterface $id,
        #[ManyToOne(inversedBy: 'spots')]
        #[JoinColumn(nullable: false, onDelete: 'CASCADE')]
        public RoundTable $table,
        #[Column]
        public int $position,
        #[ManyToOne]
        #[JoinColumn(nullable: true, onDelete: 'SET NULL')]
        public null|Player $player = null,
        #[Column(nullable: true)]
        public null|string $playerName = null,
    ) {
    }

    public function assignPlayer(Player $player): void
    {
        $this->player = $player;
        $this->playerName = null;
    }

    public function assignManualName(string $name): void
    {
        $this->playerName = $name;
        $this->player = null;
    }

    public function clearAssignment(): void
    {
        $this->player = null;
        $this->playerName = null;
    }
}
