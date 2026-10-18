<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Entity;

use DateTimeImmutable;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping\Column;
use Doctrine\ORM\Mapping\Entity;
use Doctrine\ORM\Mapping\Id;
use Doctrine\ORM\Mapping\Index;
use JetBrains\PhpStorm\Immutable;
use Ramsey\Uuid\Doctrine\UuidType;
use Ramsey\Uuid\UuidInterface;

/**
 * One run of the image pipeline's difficulty model with its own evaluation (docs/features/internal-api.md, "Puzzle
 * difficulty labels and estimates"): pushed by the Mac's `difficulty_prior` step, append-only - the latest row is the
 * current model record, the older ones its history. The Mac reads the current one before a push: a push is blocked when
 * the holdout Spearman drops > 0.05 against it (implementation-plan.md §5.3).
 *
 * `holdoutSpearman` is the number that guard compares (newer puzzles predicted from older ones); `evaluation` is the
 * rest of the run's numbers as the pipeline sent them (overall, per size group × image kind, the holdouts) - checked
 * only for its size. Not the gate record of the app's own evaluation (stream 5d), which measures stored predictions.
 */
#[Entity]
#[Index(columns: ['model', 'received_at'])]
class PuzzleDifficultyEstimateModel
{
    /**
     * @param array<string, mixed> $evaluation
     */
    public function __construct(
        #[Id]
        #[Immutable]
        #[Column(type: UuidType::NAME, unique: true)]
        public UuidInterface $id,
        #[Immutable]
        #[Column(length: 50)]
        public string $model,
        #[Immutable]
        #[Column(length: 50)]
        public string $modelVersion,
        #[Immutable]
        #[Column]
        public int $trainingSize,
        #[Immutable]
        #[Column]
        public float $holdoutSpearman,
        #[Immutable]
        #[Column(type: Types::JSONB)]
        public array $evaluation,
        // When the pipeline made the model - its own clock, the moment of the push when it did not say
        #[Immutable]
        #[Column(type: Types::DATETIME_IMMUTABLE)]
        public DateTimeImmutable $createdAt,
        // When the app stored it - "current" = the latest
        #[Immutable]
        #[Column(type: Types::DATETIME_IMMUTABLE)]
        public DateTimeImmutable $receivedAt,
    ) {
    }
}
