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
 * The sorted times the MSP rating ranks a puzzle's solvers by - public players' first attempts (one per player) and
 * best times, all time, suspicious times left out - for every puzzle of a rated piece count with at least
 * MspRatingCalculator::NEAR_QUALIFYING_MIN_SOLVERS public first-attempt solvers. Rating what-ifs binary-search them
 * (docs/features/insights/computation.md). Secret puzzles never have a row. Written only by the insights batch.
 */
#[Entity]
class PuzzleRatingTimes
{
    /**
     * @param list<int> $firstAttemptTimes ascending
     * @param list<int> $bestTimes ascending
     */
    public function __construct(
        #[Id]
        #[Immutable]
        #[OneToOne]
        #[JoinColumn(onDelete: 'CASCADE')]
        public Puzzle $puzzle,
        #[Immutable(Immutable::PRIVATE_WRITE_SCOPE)]
        #[Column]
        public int $piecesCount,
        /** Public first-attempt solvers = count of firstAttemptTimes */
        #[Immutable(Immutable::PRIVATE_WRITE_SCOPE)]
        #[Column]
        public int $solverCount,
        /** Counts in ratings: 20+ solvers and a difficulty score of more than insufficient confidence */
        #[Immutable(Immutable::PRIVATE_WRITE_SCOPE)]
        #[Column]
        public bool $qualifies,
        /** MspRatingFormula::difficultyWeight() of the difficulty the run used, null without one */
        #[Immutable(Immutable::PRIVATE_WRITE_SCOPE)]
        #[Column(nullable: true)]
        public null|float $difficultyWeight,
        #[Immutable(Immutable::PRIVATE_WRITE_SCOPE)]
        #[Column(type: Types::JSONB)]
        public array $firstAttemptTimes,
        #[Immutable(Immutable::PRIVATE_WRITE_SCOPE)]
        #[Column(type: Types::JSONB)]
        public array $bestTimes,
        #[Immutable(Immutable::PRIVATE_WRITE_SCOPE)]
        #[Column]
        public DateTimeImmutable $computedAt,
    ) {
    }
}
