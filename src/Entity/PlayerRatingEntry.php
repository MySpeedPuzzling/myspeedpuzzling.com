<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Entity;

use DateTimeImmutable;
use Doctrine\ORM\Mapping\Column;
use Doctrine\ORM\Mapping\Entity;
use Doctrine\ORM\Mapping\Id;
use Doctrine\ORM\Mapping\JoinColumn;
use Doctrine\ORM\Mapping\ManyToOne;
use JetBrains\PhpStorm\Immutable;

/**
 * One puzzle in one portfolio (RatingPortfolio: first_attempt / best_time) of a player's MSP rating at a piece count,
 * exactly as MspRatingCalculator scored it in the last batch run - for Insights (docs/features/insights/computation.md).
 * Written only by the insights batch (RatingInsightsWriter, changed rows only); public players only, like the rating.
 * Entries exist also for players not yet on the ladder.
 */
#[Entity]
class PlayerRatingEntry
{
    public function __construct(
        #[Id]
        #[Immutable]
        #[ManyToOne]
        #[JoinColumn(onDelete: 'CASCADE')]
        public Player $player,
        #[Id]
        #[Immutable]
        #[Column]
        public int $piecesCount,
        #[Id]
        #[Immutable]
        #[ManyToOne]
        #[JoinColumn(onDelete: 'CASCADE')]
        public Puzzle $puzzle,
        /** RatingPortfolio value */
        #[Id]
        #[Immutable]
        #[Column(length: 16)]
        public string $portfolio,
        #[Immutable(Immutable::PRIVATE_WRITE_SCOPE)]
        #[Column]
        public int $seconds,
        /** What the decay counts from: the first attempt, or the latest solve (best time) */
        #[Immutable(Immutable::PRIVATE_WRITE_SCOPE)]
        #[Column]
        public DateTimeImmutable $solvedAt,
        #[Immutable(Immutable::PRIVATE_WRITE_SCOPE)]
        #[Column]
        public float $percentile,
        #[Immutable(Immutable::PRIVATE_WRITE_SCOPE)]
        #[Column]
        public float $difficultyWeight,
        #[Immutable(Immutable::PRIVATE_WRITE_SCOPE)]
        #[Column]
        public float $decay,
        #[Immutable(Immutable::PRIVATE_WRITE_SCOPE)]
        #[Column]
        public float $points,
        #[Immutable(Immutable::PRIVATE_WRITE_SCOPE)]
        #[Column]
        public bool $inTop,
        #[Immutable(Immutable::PRIVATE_WRITE_SCOPE)]
        #[Column]
        public DateTimeImmutable $computedAt,
    ) {
    }
}
