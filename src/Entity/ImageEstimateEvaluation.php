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
 * The gate record of the picture-based estimates (Insights plan §5.4, docs/features/insights/image-estimate-gates.md):
 * one run of `myspeedpuzzling:evaluate-image-estimates` over the stored side-by-side predictions - append-only, the
 * latest row is the current record ImageEstimateGate reads, the older ones its history (like the pipeline's own model
 * record, PuzzleDifficultyEstimateModel, which measures the model on labels; this one measures stored predictions).
 *
 * `groups`: one entry per size group × image kind with its numbers (ImageEstimateEvaluator::groupMetrics());
 * `overall` and `byK` the same numbers pooled, for people. The gate's thresholds are applied on read
 * (ImageEstimateGate::failures()), so a record keeps meaning the same when they change.
 *
 * Gate v2 (2026-10-27): `gateVersion` says which rules a record's numbers are for (1 = the first rules, rows from
 * before - still readable, never enough for the v2 gate), `subject` what it measured (`image` = the picture estimates;
 * a candidate time model against a reference, PredictionCandidateEvaluator::subject() - the same table, so every
 * prediction gate keeps one history), `splitAt` the out-of-period split moment of its honesty numbers.
 */
#[Entity]
#[Index(columns: ['evaluated_at'])]
class ImageEstimateEvaluation
{
    /** The subject of the picture estimates' records - the only one before gate v2 */
    public const string SUBJECT_IMAGE = 'image';

    /**
     * @param list<array<string, mixed>> $groups
     * @param array<string, mixed> $overall
     * @param list<array<string, mixed>> $byK
     */
    public function __construct(
        #[Id]
        #[Immutable]
        #[Column(type: UuidType::NAME, unique: true)]
        public UuidInterface $id,
        #[Immutable]
        #[Column(type: Types::DATETIME_IMMUTABLE)]
        public DateTimeImmutable $evaluatedAt,
        // TimePredictionCalculator::MODEL_VERSION of the rows it measured
        #[Immutable]
        #[Column(type: Types::SMALLINT)]
        public int $modelVersion,
        // Newer puzzles = added on or after this moment (null = every puzzle - a candidate time model's record)
        #[Immutable]
        #[Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
        public null|DateTimeImmutable $newerThan,
        // The index count from which a puzzle's measured difficulty counts as the truth of the difficulty metrics
        #[Immutable]
        #[Column(type: Types::SMALLINT)]
        public int $measuredMinIndices,
        #[Immutable]
        #[Column(type: Types::JSONB)]
        public array $groups,
        #[Immutable]
        #[Column(type: Types::JSONB)]
        public array $overall,
        #[Immutable]
        #[Column(type: Types::JSONB)]
        public array $byK,
        // The gate rules the numbers are for (ImageEstimateEvaluator::GATE_VERSION) - rows from before gate v2 are 1
        #[Immutable]
        #[Column(type: Types::SMALLINT, options: ['default' => 1])]
        public int $gateVersion = 1,
        #[Immutable]
        #[Column(length: 80, options: ['default' => self::SUBJECT_IMAGE])]
        public string $subject = self::SUBJECT_IMAGE,
        // The out-of-period split moment of the honesty numbers (null before gate v2)
        #[Immutable]
        #[Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
        public null|DateTimeImmutable $splitAt = null,
    ) {
    }
}
