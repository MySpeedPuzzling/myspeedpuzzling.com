<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Entity;

use DateTimeImmutable;
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
#[UniqueConstraint(columns: ['player_id', 'pieces_count', 'month'])]
class PlayerSkillHistory
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
        public DateTimeImmutable $month,
        #[Column]
        public int $baselineSeconds,
        #[Column(nullable: true)]
        public null|int $skillTier = null,
        #[Column(nullable: true)]
        public null|float $skillPercentile = null,
    ) {
    }

    public function update(
        int $baselineSeconds,
        null|int $skillTier,
        null|float $skillPercentile,
    ): void {
        $this->baselineSeconds = $baselineSeconds;
        $this->skillTier = $skillTier;
        $this->skillPercentile = $skillPercentile;
    }
}
