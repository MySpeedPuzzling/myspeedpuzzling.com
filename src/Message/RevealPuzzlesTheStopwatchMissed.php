<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Message;

/**
 * "Also reveal the hidden puzzles now" of the results desk's publish (competition toolkit G3, A-round-formats.md §4.4,
 * R-review-and-plan.md §2.1.5): the round's secret puzzles that waited for a stopwatch start with "Reveal" that never
 * came are revealed now, like "Reveal now" - only when the list is still the one the organiser confirmed
 * (SecretRevealPreview::hash() of byRevealingWhatTheStopwatchMissed()). Dispatched by the controller after the publish,
 * in its own transaction: the publish never waits for it and never rolls back with it. `competitionId` is the
 * competition the caller authorised; a round of another one is refused.
 */
readonly final class RevealPuzzlesTheStopwatchMissed
{
    public function __construct(
        public string $competitionId,
        public string $roundId,
        public string $confirmedHash,
    ) {
    }
}
