<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Results;

/**
 * What CompetitionDisplaysPresence knows of one event (or of a round's event): whether it has displays at all and
 * whether any of them shows results (`uses_results`, derived from the design on every save).
 */
readonly final class CompetitionDisplaysPresent
{
    public function __construct(
        public null|string $competitionId,
        public bool $displays,
        public bool $results,
    ) {
    }

    public static function none(null|string $competitionId): self
    {
        return new self($competitionId, false, false);
    }
}
