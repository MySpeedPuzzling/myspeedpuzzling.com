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
 * The candidate prediction model's difficulty-adjusted pace (v7 - docs/features/puzzle-intelligence/README.md "Step 8",
 * "Candidate v7") of a player at a piece count, as of right after one of their qualifying first tries there (from the
 * 5th on, like the pace history's rows): the log offset the candidate adds to that pace-history row's pace and drift
 * (Model\AdjustedPace) - the player's level on the average puzzle instead of on the puzzles they picked. Valid from that
 * first try's moment (its solve, then its tracking moment - the order every as-of read of the models uses); a row is
 * written only where the offset moved (CandidatePaceOffsets reads the latest before a moment). Rewritten by the
 * candidate's daily fit in SQL (CandidateModelRecalculator); never shown, never read by the shown model. No foreign key,
 * like the candidate's other tables: a deleted player's rows go with the next fit.
 */
#[Entity]
class CandidatePaceOffset
{
    public function __construct(
        #[Id]
        #[Immutable]
        #[Column(type: UuidType::NAME)]
        public UuidInterface $playerId,
        #[Id]
        #[Immutable]
        #[Column]
        public int $piecesCount,
        #[Id]
        #[Immutable]
        #[Column]
        public DateTimeImmutable $validFrom,
        #[Id]
        #[Immutable]
        #[Column]
        public DateTimeImmutable $validFromTrackedAt,
        #[Immutable]
        #[Column]
        public float $paceOffset,
        #[Immutable]
        #[Column]
        public DateTimeImmutable $computedAt,
    ) {
    }
}
