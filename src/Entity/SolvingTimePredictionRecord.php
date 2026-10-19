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
use Doctrine\ORM\Mapping\Table;
use Doctrine\ORM\Mapping\UniqueConstraint;
use JetBrains\PhpStorm\Immutable;
use Ramsey\Uuid\Doctrine\UuidType;
use Ramsey\Uuid\UuidInterface;
use SpeedPuzzling\Web\Value\DifficultySource;
use SpeedPuzzling\Web\Value\PaceSource;
use SpeedPuzzling\Web\Value\PredictorKind;
use SpeedPuzzling\Web\Value\SolvingTimePrediction;
use SpeedPuzzling\Web\Value\TimePredictionMethod;
use SpeedPuzzling\Web\Value\TimePredictionSource;

/**
 * What one predictor of one model version predicted for a solo time - side by side with the other predictors
 * (docs/features/puzzle-intelligence/prediction-history.md, "Side by side"). The columns on puzzle_solving_time stay
 * the prediction that is shown; these rows are for comparing predictors and models (accuracy, calibration, Insights'
 * expected vs actual). Written live by SolvingTimePredictor and reconstructed by the prediction backfill; records no
 * domain event. Goes with its time (cascade).
 */
#[Entity]
#[Table(name: 'solving_time_prediction')]
#[UniqueConstraint(columns: ['time_id', 'predictor', 'model_version'])]
class SolvingTimePredictionRecord
{
    #[Immutable(Immutable::PRIVATE_WRITE_SCOPE)]
    #[Column(type: Types::STRING, enumType: TimePredictionSource::class)]
    public TimePredictionSource $source;

    #[Immutable(Immutable::PRIVATE_WRITE_SCOPE)]
    #[Column]
    public bool $predictable;

    // personal (a repeat) / statistical (a first try); null when not predictable
    #[Immutable(Immutable::PRIVATE_WRITE_SCOPE)]
    #[Column(type: Types::STRING, nullable: true, enumType: TimePredictionMethod::class)]
    public null|TimePredictionMethod $method = null;

    #[Immutable(Immutable::PRIVATE_WRITE_SCOPE)]
    #[Column(nullable: true)]
    public null|int $predictedSeconds = null;

    #[Immutable(Immutable::PRIVATE_WRITE_SCOPE)]
    #[Column(nullable: true)]
    public null|int $range50Low = null;

    #[Immutable(Immutable::PRIVATE_WRITE_SCOPE)]
    #[Column(nullable: true)]
    public null|int $range50High = null;

    #[Immutable(Immutable::PRIVATE_WRITE_SCOPE)]
    #[Column(nullable: true)]
    public null|int $range80Low = null;

    #[Immutable(Immutable::PRIVATE_WRITE_SCOPE)]
    #[Column(nullable: true)]
    public null|int $range80High = null;

    // The puzzle difficulty (index scale, 1.0 = average) a first try was predicted with
    #[Immutable(Immutable::PRIVATE_WRITE_SCOPE)]
    #[Column(nullable: true)]
    public null|float $difficultyUsed = null;

    #[Immutable(Immutable::PRIVATE_WRITE_SCOPE)]
    #[Column(type: Types::STRING, nullable: true, enumType: DifficultySource::class)]
    public null|DifficultySource $difficultySource = null;

    #[Immutable(Immutable::PRIVATE_WRITE_SCOPE)]
    #[Column(nullable: true)]
    public null|int $kIndices = null;

    // What the pace of a first try rested on - the time verification leaves the 500-scaled one out
    #[Immutable(Immutable::PRIVATE_WRITE_SCOPE)]
    #[Column(type: Types::STRING, length: 20, nullable: true, enumType: PaceSource::class)]
    public null|PaceSource $paceSource = null;

    #[Immutable(Immutable::PRIVATE_WRITE_SCOPE)]
    #[Column(nullable: true)]
    public null|float $priorLog = null;

    // The calibration bucket of the window (WindowBucket::key()) - what the calibration report groups by
    #[Immutable(Immutable::PRIVATE_WRITE_SCOPE)]
    #[Column(length: 40, nullable: true)]
    public null|string $windowBucket = null;

    #[Immutable(Immutable::PRIVATE_WRITE_SCOPE)]
    #[Column]
    public DateTimeImmutable $computedAt;

    public function __construct(
        #[Id]
        #[Immutable]
        #[Column(type: UuidType::NAME, unique: true)]
        public UuidInterface $id,
        #[Immutable]
        #[ManyToOne]
        #[JoinColumn(nullable: false, onDelete: 'CASCADE')]
        public PuzzleSolvingTime $time,
        #[Immutable]
        #[Column(type: Types::STRING, enumType: PredictorKind::class)]
        public PredictorKind $predictor,
        #[Immutable]
        #[Column(type: Types::SMALLINT)]
        public int $modelVersion,
        SolvingTimePrediction $prediction,
        DateTimeImmutable $computedAt,
    ) {
        $this->record($prediction, $computedAt);
    }

    /**
     * A reconstruction of the same predictor and model version replaces what was there (an edit made it obsolete).
     */
    public function record(SolvingTimePrediction $prediction, DateTimeImmutable $computedAt): void
    {
        $result = $prediction->result;

        $this->source = $prediction->source;
        $this->predictable = $result !== null;
        $this->method = match (true) {
            $result === null => null,
            $result->isPersonalized => TimePredictionMethod::Personal,
            default => TimePredictionMethod::Statistical,
        };
        $this->predictedSeconds = $result?->predictedSeconds;
        $this->range50Low = $result?->rangeLowSeconds;
        $this->range50High = $result?->rangeHighSeconds;
        $this->range80Low = $result?->range80LowSeconds;
        $this->range80High = $result?->range80HighSeconds;
        $this->difficultyUsed = $result !== null && $result->isPersonalized === false ? $result->difficultyForPlayer : null;
        $this->difficultySource = $result?->difficultySource;
        $this->kIndices = $result?->kIndices;
        $this->paceSource = $result?->paceSource;
        $this->priorLog = $result?->priorLog;
        $this->windowBucket = $result?->windowBucket;
        $this->computedAt = $computedAt;
    }
}
