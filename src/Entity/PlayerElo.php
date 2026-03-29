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
#[UniqueConstraint(columns: ['player_id', 'pieces_count', 'period'])]
#[Index(columns: ['pieces_count', 'period', 'elo_rating'])]
class PlayerElo
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
        public string $period,
        #[Column(options: ['default' => 1000])]
        public int $eloRating = 1000,
        #[Column(options: ['default' => 0])]
        public int $matchesCount = 0,
        #[Column(nullable: true)]
        public null|DateTimeImmutable $lastSolveAt = null,
        #[Column]
        public DateTimeImmutable $computedAt = new DateTimeImmutable(),
    ) {
    }

    public function updateRating(
        int $eloRating,
        int $matchesCount,
        null|DateTimeImmutable $lastSolveAt,
        DateTimeImmutable $computedAt,
    ): void {
        $this->eloRating = $eloRating;
        $this->matchesCount = $matchesCount;
        $this->lastSolveAt = $lastSolveAt;
        $this->computedAt = $computedAt;
    }
}
