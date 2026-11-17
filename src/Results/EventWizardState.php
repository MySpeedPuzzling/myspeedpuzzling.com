<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Results;

/**
 * What the event creation wizard derives its path and its steps' "done" from (GetEventWizardState) - the draft is the
 * wizard's only state.
 */
readonly final class EventWizardState
{
    public function __construct(
        public string $competitionId,
        public bool $isDraft,
        public bool $isEdition,
        public int $roundsCount,
        // The event's round while it has exactly one - the one-round path's round
        public null|string $singleRoundId,
        // The puzzles of all its rounds
        public int $roundPuzzlesCount,
        // Who created it - a new event's page sent twice finds its own draft by it (EventWizardBasicsController)
        public null|string $addedByPlayerId = null,
        // One of the Extras step's features is on: registration here or elsewhere, a judge, competitor pages or QR tags,
        // a display (EventWizardSteps: the step is done)
        public bool $extrasOn = false,
        // Its rounds without any puzzle yet (several rounds: the Puzzles step is done when none is left)
        public int $roundsWithoutPuzzle = 0,
    ) {
    }
}
