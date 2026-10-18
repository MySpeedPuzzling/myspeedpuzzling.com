<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Entity;

use DateTimeImmutable;
use Doctrine\ORM\Mapping\Column;
use Doctrine\ORM\Mapping\Entity;
use Doctrine\ORM\Mapping\Id;
use JetBrains\PhpStorm\Immutable;

/**
 * One row once player_pace_history has been built for every player (the first full batch sync or the
 * backfill). Until then the as-of difficulty must not replace a stored difficulty: an empty or half
 * built history would give puzzles no indices. Written and read by PaceHistoryReadiness only.
 */
#[Entity]
class PaceHistoryBuild
{
    public function __construct(
        #[Id]
        #[Immutable]
        #[Column]
        public int $id,
        // PlayerPaceHistoryCalculator::VERSION of the first complete build
        #[Immutable]
        #[Column]
        public int $version,
        #[Immutable]
        #[Column]
        public DateTimeImmutable $builtAt,
        // The baselines' scaling exponent of the last full sync - what the incremental path and the
        // reconstructor interpolate with, so they agree with the batch without computing it again
        #[Immutable]
        #[Column(nullable: true)]
        public null|float $scalingExponent = null,
    ) {
    }
}
