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
 * difficulty indices and their count then - left out of its own prior, so a puzzle never shrinks toward itself.
 * Written with the groups in one transaction by the insights batch (PredictionModelRecalculator), in SQL. No foreign
 * key, like the groups: a merged puzzle's row is left for the next fit to remove.
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
        #[Column]
        public DateTimeImmutable $computedAt,
    ) {
    }
}
