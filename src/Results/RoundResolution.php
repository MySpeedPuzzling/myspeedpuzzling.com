<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Results;

use SpeedPuzzling\Web\Entity\CompetitionRound;

/**
 * The answer of the round rule for one solving time (SolvingTimeRoundResolver, SolvingTimeRoundMatch): its round or
 * none (no candidate, or the evidence does not single out one), whether it is a round the player named and kept
 * (`chosen`), and how many candidate rounds the time had (competition + puzzle + category).
 */
readonly final class RoundResolution
{
    public function __construct(
        public null|CompetitionRound $round,
        public bool $chosen,
        public int $candidates,
    ) {
    }

    public static function none(): self
    {
        return new self(null, false, 0);
    }
}
