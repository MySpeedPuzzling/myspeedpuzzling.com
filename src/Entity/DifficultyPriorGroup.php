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
 * BrandPiecesPrior's fit: the difficulty indices of one brand at one piece count summed up (as of the last daily fit
 * of the insights batch, PredictionModelRecalculator) - BrandPiecesPrior::fromSums() turns them into the prior, the
 * puzzle's own share left out. Read by the prediction statements with a join on the puzzle's brand and piece count. No foreign
 * key: a merged brand's rows are left for the next fit to remove (a puzzle never points at them any more). Written
 * in SQL by the batch only.
 */
#[Entity]
class DifficultyPriorGroup
{
    public function __construct(
        #[Id]
        #[Immutable]
        #[Column(type: UuidType::NAME)]
        public UuidInterface $manufacturerId,
        #[Id]
        #[Immutable]
        #[Column]
        public int $piecesCount,
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
