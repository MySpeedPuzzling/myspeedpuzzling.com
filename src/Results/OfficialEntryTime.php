<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Results;

use DateTimeImmutable;

/**
 * What "Add to my profile" of a published official result fills into the add-time form (OfficialEntryTimePrefill,
 * docs/features/competitions-management/official-results.md). Only a pre-fill: the player checks and saves it like
 * any other time - nothing about the official entry is stored with it.
 */
readonly final class OfficialEntryTime
{
    /**
     * @param list<string> $groupPlayers co-puzzlers as the form posts them: "#CODE" of a linked member, else a guest name
     */
    public function __construct(
        public string $puzzleId,
        public int $seconds,
        // The round's start date in the round's time zone
        public DateTimeImmutable $finishedAt,
        public array $groupPlayers,
        // The pair's/team's name - only when the form may still name it (no such pair/team yet, or an unnamed one)
        public null|string $teamName,
        public string $roundName,
        public string $competitionName,
        // Nobody of the pair/team is linked and the viewer's name is not clearly one of them - they may be listed as a guest
        public bool $viewerMayBeAmongGuests,
    ) {
    }

    public function hours(): int
    {
        return intdiv($this->seconds, 3600);
    }

    public function minutes(): int
    {
        return intdiv($this->seconds % 3600, 60);
    }

    public function secondsPart(): int
    {
        return $this->seconds % 60;
    }
}
