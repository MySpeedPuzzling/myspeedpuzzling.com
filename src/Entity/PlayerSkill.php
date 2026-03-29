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
use SpeedPuzzling\Web\Value\MetricConfidence;
use SpeedPuzzling\Web\Value\SkillTier;

#[Entity]
#[UniqueConstraint(columns: ['player_id', 'pieces_count'])]
#[Index(columns: ['pieces_count'])]
#[Index(columns: ['skill_tier'])]
class PlayerSkill
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
        public float $skillScore,
        #[Column]
        public int $skillTier,
        #[Column]
        public float $skillPercentile,
        #[Column]
        public string $confidence,
        #[Column]
        public int $qualifyingPuzzlesCount,
        #[Column]
        public DateTimeImmutable $computedAt,
    ) {
    }

    public function update(
        float $skillScore,
        SkillTier $skillTier,
        float $skillPercentile,
        MetricConfidence $confidence,
        int $qualifyingPuzzlesCount,
        DateTimeImmutable $computedAt,
    ): void {
        $this->skillScore = $skillScore;
        $this->skillTier = $skillTier->value;
        $this->skillPercentile = $skillPercentile;
        $this->confidence = $confidence->value;
        $this->qualifyingPuzzlesCount = $qualifyingPuzzlesCount;
        $this->computedAt = $computedAt;
    }
}
