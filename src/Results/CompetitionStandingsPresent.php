<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Results;

/**
 * What CompetitionStandingsPresence knows of a round (or of an event): whether a published standing counts the round,
 * whether the round's results are published, and (event) whether the event has any published standing.
 */
readonly final class CompetitionStandingsPresent
{
    public function __construct(
        public null|string $competitionId,
        public bool $inPublishedStanding,
        public bool $roundPublished,
        public bool $anyPublished,
    ) {
    }

    public static function none(null|string $competitionId): self
    {
        return new self($competitionId, false, false, false);
    }
}
