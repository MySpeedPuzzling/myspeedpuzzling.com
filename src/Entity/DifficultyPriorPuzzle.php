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
 * never shrinks toward itself - and its finished prior then (prior_log, null = none): what the prior chain gives
 * (BrandPiecesPrior today; Insights 5d writes the image prior of puzzles without a brand prior into this same column).
 * A row for every puzzle with a share or a prior. The Puzzle Picker reads prior_log - one lookup per candidate; the
 * other readers build the prior from the sums (PriorContext). Written with the groups in one transaction by the daily
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
    ) {
    }
}
