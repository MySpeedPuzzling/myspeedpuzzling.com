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
 * The candidate prediction model's (v4, never shown) brand × pieces prior of a puzzle - over the group's puzzles, not
 * its indices (PuzzleWeightedBrandPrior), the puzzle itself left out. Written only by the candidate's own daily fit
 * (CandidateModelRecalculator, in SQL - a row per puzzle with one); read by the candidate's live and reconstructed
 * predictions. Kept apart from the shown model's difficulty_prior_puzzle, so the shown fit never touches it. No foreign
 * key: a merged puzzle's row is removed by the next fit.
 */
#[Entity]
class CandidatePuzzlePrior
{
    public function __construct(
        #[Id]
        #[Immutable]
        #[Column(type: UuidType::NAME)]
        public UuidInterface $puzzleId,
        #[Immutable]
        #[Column]
        public float $brandPriorLog,
        #[Immutable]
        #[Column]
        public DateTimeImmutable $computedAt,
    ) {
    }
}
