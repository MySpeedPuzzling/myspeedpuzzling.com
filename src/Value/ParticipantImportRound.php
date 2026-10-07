<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Value;

/**
 * A round of the event an uploaded list is mapped against.
 */
readonly final class ParticipantImportRound
{
    public function __construct(
        public string $id,
        public string $name,
        public RoundCategory $category,
    ) {
    }

    public function hasTeams(): bool
    {
        return $this->category !== RoundCategory::Solo;
    }
}
