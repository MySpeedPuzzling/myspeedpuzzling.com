<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Entity;

use DateTimeImmutable;
use Doctrine\ORM\Mapping\Column;
use Doctrine\ORM\Mapping\Entity;
use Doctrine\ORM\Mapping\Id;
use Doctrine\ORM\Mapping\Index;
use Doctrine\ORM\Mapping\JoinColumn;
use Doctrine\ORM\Mapping\OneToOne;
use JetBrains\PhpStorm\Immutable;
use SpeedPuzzling\Web\Value\DifficultyTier;
use SpeedPuzzling\Web\Value\MetricConfidence;

#[Entity]
#[Index(columns: ['difficulty_tier'])]
class PuzzleDifficulty
{
    #[Immutable(Immutable::PRIVATE_WRITE_SCOPE)]
    #[Column(nullable: true)]
    public null|float $difficultyScore = null;

    #[Immutable(Immutable::PRIVATE_WRITE_SCOPE)]
    #[Column(nullable: true)]
    public null|int $difficultyTier = null;

    #[Immutable(Immutable::PRIVATE_WRITE_SCOPE)]
    #[Column]
    public string $confidence = MetricConfidence::Insufficient->value;

    #[Immutable(Immutable::PRIVATE_WRITE_SCOPE)]
    #[Column(options: ['default' => 0])]
    public int $sampleSize = 0;

    #[Immutable(Immutable::PRIVATE_WRITE_SCOPE)]
    #[Column(nullable: true)]
    public null|float $memorabilityScore = null;

    #[Immutable(Immutable::PRIVATE_WRITE_SCOPE)]
    #[Column(nullable: true)]
    public null|float $skillSensitivityScore = null;

    #[Immutable(Immutable::PRIVATE_WRITE_SCOPE)]
    #[Column(nullable: true)]
    public null|float $predictabilityScore = null;

    #[Immutable(Immutable::PRIVATE_WRITE_SCOPE)]
    #[Column(nullable: true)]
    public null|float $boxDependenceScore = null;

    #[Immutable(Immutable::PRIVATE_WRITE_SCOPE)]
    #[Column(nullable: true)]
    public null|float $improvementCeilingScore = null;

    #[Immutable(Immutable::PRIVATE_WRITE_SCOPE)]
    #[Column(nullable: true)]
    public null|float $indicesP25 = null;

    #[Immutable(Immutable::PRIVATE_WRITE_SCOPE)]
    #[Column(nullable: true)]
    public null|float $indicesP75 = null;

    #[Immutable(Immutable::PRIVATE_WRITE_SCOPE)]
    #[Column]
    public DateTimeImmutable $computedAt;

    public function __construct(
        #[Id]
        #[Immutable]
        #[OneToOne]
        #[JoinColumn(onDelete: 'CASCADE')]
        public Puzzle $puzzle,
    ) {
        $this->computedAt = new DateTimeImmutable();
    }

    public function updateDifficulty(
        null|float $difficultyScore,
        MetricConfidence $confidence,
        int $sampleSize,
        DateTimeImmutable $computedAt,
        null|float $indicesP25 = null,
        null|float $indicesP75 = null,
    ): void {
        $this->difficultyScore = $difficultyScore;
        $this->difficultyTier = $difficultyScore !== null ? DifficultyTier::fromScore($difficultyScore)->value : null;
        $this->confidence = $confidence->value;
        $this->sampleSize = $sampleSize;
        $this->indicesP25 = $indicesP25;
        $this->indicesP75 = $indicesP75;
        $this->computedAt = $computedAt;
    }

    public function updateDerivedMetrics(
        null|float $memorabilityScore,
        null|float $skillSensitivityScore,
        null|float $predictabilityScore,
        null|float $boxDependenceScore,
        null|float $improvementCeilingScore,
    ): void {
        $this->memorabilityScore = $memorabilityScore;
        $this->skillSensitivityScore = $skillSensitivityScore;
        $this->predictabilityScore = $predictabilityScore;
        $this->boxDependenceScore = $boxDependenceScore;
        $this->improvementCeilingScore = $improvementCeilingScore;
    }
}
