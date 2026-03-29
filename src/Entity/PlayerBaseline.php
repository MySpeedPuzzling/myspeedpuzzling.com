<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Entity;

use DateTimeImmutable;
use Doctrine\ORM\Mapping\Column;
use Doctrine\ORM\Mapping\Entity;
use Doctrine\ORM\Mapping\Id;
use Doctrine\ORM\Mapping\Index;
use Doctrine\ORM\Mapping\JoinColumn;
use Doctrine\ORM\Mapping\ManyToOne;
use Doctrine\ORM\Mapping\UniqueConstraint;
use JetBrains\PhpStorm\Immutable;
use Ramsey\Uuid\Doctrine\UuidType;
use Ramsey\Uuid\UuidInterface;

#[Entity]
#[UniqueConstraint(columns: ['player_id', 'pieces_count'])]
#[Index(columns: ['pieces_count'])]
class PlayerBaseline
{
    public function __construct(
        #[Id]
        #[Immutable]
        #[Column(type: UuidType::NAME, unique: true)]
        public UuidInterface $id,
        #[ManyToOne]
        #[JoinColumn(nullable: false, onDelete: 'CASCADE')]
        public Player $player,
        #[Column]
        public int $piecesCount,
        #[Column]
        public int $baselineSeconds,
        #[Column]
        public int $qualifyingSolvesCount,
        #[Column]
        public DateTimeImmutable $computedAt,
        #[Column(options: ['default' => 'direct'])]
        public string $baselineType = 'direct',
    ) {
    }

    public function update(
        int $baselineSeconds,
        int $qualifyingSolvesCount,
        string $baselineType,
        DateTimeImmutable $computedAt,
    ): void {
        $this->baselineSeconds = $baselineSeconds;
        $this->qualifyingSolvesCount = $qualifyingSolvesCount;
        $this->baselineType = $baselineType;
        $this->computedAt = $computedAt;
    }
}
