<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Results;

use DateTimeImmutable;
use SpeedPuzzling\Web\Value\RoundCategory;

/**
 * One edition of a series as the event creation wizard's series path shows it (Review) and reads it (the "Add the next
 * dates" prefill): its day, its first round - the one the wizard gives every date - and how many it has.
 */
readonly final class EventWizardSeriesEdition
{
    public function __construct(
        public string $competitionId,
        public string $name,
        public bool $isDraft,
        // The edition's own day (AddEditions dates it at 00:00 UTC)
        public null|DateTimeImmutable $dateFrom,
        public int $roundsCount,
        public int $puzzlesCount,
        public null|string $roundId = null,
        public null|string $roundName = null,
        public null|DateTimeImmutable $roundStartsAt = null,
        public null|string $roundTimezone = null,
        public null|RoundCategory $roundCategory = null,
        public null|int $roundTeamSize = null,
        public null|int $roundMinutesLimit = null,
        public bool $roundStartTimeKnown = true,
    ) {
    }
}
