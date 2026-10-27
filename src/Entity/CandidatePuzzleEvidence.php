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
 * What the candidate prediction model (v4, never shown - docs/features/puzzle-intelligence/README.md "Step 8", "The
 * candidate model (v4)") knows about a puzzle's difficulty now: its drift-adjusted, precision-weighted difficulty
 * indices as sufficient statistics (CandidateEvidence). The shown difficulty (puzzle_difficulty) is untouched. Written by
 * the insights batch in SQL (PredictionModelRecalculator, once the candidate is fitted), only the rows that changed; read
 * by the live side-by-side prediction of an added time. No foreign key, like the priors: a merged puzzle's row is
 * removed by the next batch.
 */
#[Entity]
class CandidatePuzzleEvidence
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
        public float $weightSum,
        #[Immutable]
        #[Column]
        public float $weightedLogSum,
        #[Immutable]
        #[Column]
        public float $logSum,
        #[Immutable]
        #[Column]
        public float $logSquareSum,
        #[Immutable]
        #[Column]
        public float $noiseSum,
        #[Immutable]
        #[Column]
        public DateTimeImmutable $computedAt,
    ) {
    }
}
