<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Entity;

use DateTimeImmutable;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping\Column;
use Doctrine\ORM\Mapping\Entity;
use Doctrine\ORM\Mapping\Id;
use Doctrine\ORM\Mapping\JoinColumn;
use Doctrine\ORM\Mapping\OneToOne;
use JetBrains\PhpStorm\Immutable;

/**
 * A puzzle's difficulty estimated from its picture (docs/features/insights/implementation-plan.md §5.2,
 * docs/features/internal-api.md "Puzzle difficulty labels and estimates"): computed by the image pipeline's nightly
 * ridge model on Jan's Mac (`difficulty_prior`) and pushed through the internal API - the current value only, one row
 * per puzzle, replaced by every newer push.
 *
 * In log space like the label it was trained on: `logDifficulty` = ln of the difficulty index (1.0 = an average puzzle
 * for the solver, ln 0), `logP10` / `logP90` its 80 % interval. `outOfFold` = the puzzle had a label and this value
 * comes from a model that did not see it (cross-fitted) - the only kind a reconstructed prediction may use. Valid for
 * the picture it was made from (`imageFingerprint`, PuzzleImageFingerprint) - readers take only the current one
 * (GetPuzzleDifficultyEstimates). Deleted with the puzzle - a merge drops the merged puzzle's estimate, the survivor
 * keeps its own.
 */
#[Entity]
class PuzzleDifficultyEstimate
{
    public function __construct(
        #[Id]
        #[Immutable]
        #[OneToOne]
        #[JoinColumn(onDelete: 'CASCADE')]
        public Puzzle $puzzle,
        #[Immutable(Immutable::PRIVATE_WRITE_SCOPE)]
        #[Column(length: 50)]
        public string $model,
        #[Immutable(Immutable::PRIVATE_WRITE_SCOPE)]
        #[Column(length: 50)]
        public string $modelVersion,
        #[Immutable(Immutable::PRIVATE_WRITE_SCOPE)]
        #[Column]
        public float $logDifficulty,
        #[Immutable(Immutable::PRIVATE_WRITE_SCOPE)]
        #[Column]
        public float $logP10,
        #[Immutable(Immutable::PRIVATE_WRITE_SCOPE)]
        #[Column]
        public float $logP90,
        #[Immutable(Immutable::PRIVATE_WRITE_SCOPE)]
        #[Column]
        public bool $outOfFold,
        // Labelled puzzles the model was trained on
        #[Immutable(Immutable::PRIVATE_WRITE_SCOPE)]
        #[Column]
        public int $trainingSize,
        #[Immutable(Immutable::PRIVATE_WRITE_SCOPE)]
        #[Column(length: 64)]
        public string $imageFingerprint,
        // When the pipeline computed it - its own clock, the moment of the push when it did not say
        #[Immutable(Immutable::PRIVATE_WRITE_SCOPE)]
        #[Column(type: Types::DATETIME_IMMUTABLE)]
        public DateTimeImmutable $computedAt,
        // When the app stored it
        #[Immutable(Immutable::PRIVATE_WRITE_SCOPE)]
        #[Column(type: Types::DATETIME_IMMUTABLE)]
        public DateTimeImmutable $receivedAt,
    ) {
    }

    /**
     * A newer push for the same puzzle - it replaces this one whole.
     */
    public function replace(
        string $model,
        string $modelVersion,
        float $logDifficulty,
        float $logP10,
        float $logP90,
        bool $outOfFold,
        int $trainingSize,
        string $imageFingerprint,
        DateTimeImmutable $computedAt,
        DateTimeImmutable $receivedAt,
    ): void {
        $this->model = $model;
        $this->modelVersion = $modelVersion;
        $this->logDifficulty = $logDifficulty;
        $this->logP10 = $logP10;
        $this->logP90 = $logP90;
        $this->outOfFold = $outOfFold;
        $this->trainingSize = $trainingSize;
        $this->imageFingerprint = $imageFingerprint;
        $this->computedAt = $computedAt;
        $this->receivedAt = $receivedAt;
    }
}
