<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Entity;

use DateTimeImmutable;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping\Column;
use Doctrine\ORM\Mapping\Entity;
use Doctrine\ORM\Mapping\Id;
use JetBrains\PhpStorm\Immutable;

/**
 * The pair/team coordination constant c(size, first try or repeat): a group's time ÷ (difficulty × 1 / Σ 1/baseline),
 * the median over qualifying group results (docs/features/insights/computation.md). Refit daily by
 * `myspeedpuzzling:recalculate-insights-daily`; a seed value while too few results exist (Value\GroupCoordination).
 */
#[Entity]
class GroupCoordinationConstant
{
    public function __construct(
        /** 2 = pair, 3 = team of 3, 4 = team of 4 or more */
        #[Id]
        #[Immutable]
        #[Column(type: Types::SMALLINT)]
        public int $groupSize,
        #[Id]
        #[Immutable]
        #[Column]
        public bool $firstAttempt,
        #[Immutable(Immutable::PRIVATE_WRITE_SCOPE)]
        #[Column]
        public float $coordination,
        #[Immutable(Immutable::PRIVATE_WRITE_SCOPE)]
        #[Column]
        public int $sampleSize,
        /** false = the seed value (too few results to fit) */
        #[Immutable(Immutable::PRIVATE_WRITE_SCOPE)]
        #[Column]
        public bool $fitted,
        #[Immutable(Immutable::PRIVATE_WRITE_SCOPE)]
        #[Column]
        public DateTimeImmutable $computedAt,
    ) {
    }
}
