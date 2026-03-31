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
#[UniqueConstraint(columns: ['player_id', 'from_attempt'])]
class PlayerImprovementRatio
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
        public int $fromAttempt,
        #[Column]
        public float $medianRatio,
        #[Column]
        public int $sampleSize,
        #[Column]
        public DateTimeImmutable $computedAt = new DateTimeImmutable(),
    ) {
    }
}
