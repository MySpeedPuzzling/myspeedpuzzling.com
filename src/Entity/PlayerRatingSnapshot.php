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
#[UniqueConstraint(columns: ['player_id', 'pieces_count', 'snapshot_date'])]
#[Index(columns: ['player_id', 'snapshot_date'])]
class PlayerRatingSnapshot
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
        public DateTimeImmutable $snapshotDate,
        #[Column(nullable: true)]
        public null|float $skillScore = null,
        #[Column(nullable: true)]
        public null|int $skillTier = null,
        #[Column(nullable: true)]
        public null|float $skillPercentile = null,
        #[Column(nullable: true)]
        public null|float $eloRating = null,
        #[Column(nullable: true)]
        public null|int $eloRank = null,
        #[Column(nullable: true)]
        public null|int $baselineSeconds = null,
        #[Column(nullable: true)]
        public null|string $baselineType = null,
        #[Column]
        public DateTimeImmutable $computedAt = new DateTimeImmutable(),
    ) {
    }
}
