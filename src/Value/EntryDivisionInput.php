<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Value;

/**
 * One round entry's division as asked (SetRoundEntryDivisions): the entry by its participant (a solo round; in a
 * pair/team round, the pair/team the participant is in) or by its team, and the division - its id, name or short label
 * (case does not matter), null to clear it.
 */
readonly final class EntryDivisionInput
{
    public function __construct(
        public null|string $participantId,
        public null|string $teamId,
        public null|string $division,
    ) {
    }
}
