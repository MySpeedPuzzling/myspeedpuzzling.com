<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Value;

/**
 * A round of the event an uploaded list is mapped against (and the participants sheet plans against).
 */
readonly final class ParticipantImportRound
{
    public function __construct(
        public string $id,
        public string $name,
        public RoundCategory $category,
        // The organiser's expected team size (CompetitionRound::$teamSize) - team rounds only, null = not set
        public null|int $teamSize = null,
    ) {
    }

    public function hasTeams(): bool
    {
        return $this->category !== RoundCategory::Solo;
    }
}
