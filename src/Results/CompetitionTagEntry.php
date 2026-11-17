<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Results;

use SpeedPuzzling\Web\Value\RoundEntryRef;

/**
 * One round entry a scanned competition tag stands for in one round (GetCompetitionTagEntries) - null `entry` = the
 * tag's person is in the round without being an entry of it (a pair/team round, no pair/team yet).
 */
readonly final class CompetitionTagEntry
{
    public function __construct(
        public string $roundId,
        public null|RoundEntryRef $entry,
    ) {
    }
}
