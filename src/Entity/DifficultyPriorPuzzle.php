<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Entity;

use DateTimeImmutable;
use Doctrine\ORM\Mapping\Column;
use Doctrine\ORM\Mapping\Entity;
use Doctrine\ORM\Mapping\Id;
use JetBrains\PhpStorm\Immutable;
use Ramsey\Uuid\Doctrine\UuidType;
use Ramsey\Uuid\UuidInterface;

/**
 * One puzzle's share in its BrandPiecesPrior group (DifficultyPriorGroup) as of the last daily fit: the sum of its log
 * difficulty indices and their count then (0 / 0 for a puzzle without indices) - left out of its own prior, so a puzzle
 * never shrinks toward itself - and its finished priors then (null = none): `prior_log` = what the statistical (shown)
 * predictor's chain gives (brand × pieces - the image estimate never reaches it), `blend_prior_log` = the blend
 * predictor's, with the estimate of the puzzle's current picture in front (ImageEstimatePrior, Insights 5d; equal to
 * prior_log without an estimate). A row for every puzzle with a share or a prior. The Puzzle Picker reads prior_log -
 * one lookup per candidate; the other readers build the prior from the sums and the estimate (PriorContext). Written with the groups in one transaction by the daily
 * fit (PredictionModelRecalculator), in SQL. No foreign key, like the groups: a merged puzzle's row is left for the next
 * fit to remove.
 */
#[Entity]
class DifficultyPriorPuzzle
{
    public function __construct(
        #[Id]
        #[Immutable]
        #[Column(type: UuidType::NAME)]
        public UuidInterface $puzzleId,
        #[Immutable]
        #[Column]
        public int $indexCount,
        #[Immutable]
        #[Column]
        public float $sumLogIndices,
        #[Immutable]
        #[Column(nullable: true)]
        public null|float $priorLog,
        #[Immutable]
        #[Column]
        public DateTimeImmutable $computedAt,
        #[Immutable]
        #[Column(nullable: true)]
        public null|float $blendPriorLog = null,
        // The candidate model's (v4, never shown) brand × pieces prior: over the group's puzzles, not its indices
        // (PuzzleWeightedBrandPrior); null = none
        #[Immutable]
        #[Column(nullable: true)]
        public null|float $candidatePriorLog = null,
    ) {
    }
}
