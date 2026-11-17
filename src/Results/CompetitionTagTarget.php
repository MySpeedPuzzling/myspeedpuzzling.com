<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Results;

use SpeedPuzzling\Web\Value\CompetitionTag;
use SpeedPuzzling\Web\Value\RoundEntryRef;

/**
 * Where a scanned competition tag leads now (CompetitionTagResolver): the round - the tag's current one, or for a
 * round-entry tag its own - and the tag's entry in it.
 */
readonly final class CompetitionTagTarget
{
    public function __construct(
        public CompetitionTag $tag,
        // The tag stands for something of the event: an active participant, a line-up playing in some round, an entry,
        // a table number some round uses
        public bool $known,
        // null = the event has no rounds
        public null|RoundResultsOverview $round,
        // The tag's entry in that round - null for an unknown tag, an empty table, or a person without a pair/team yet
        public null|RoundEntryRef $entry,
    ) {
    }
}
