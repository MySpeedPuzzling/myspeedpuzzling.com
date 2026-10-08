<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Value;

/**
 * Why a round cannot move to another competition (MoveRoundToCompetition, docs/features/organizations/README.md
 * "Restructuring tools"). The one-round-per-category invariant of the target has its own exception
 * (PuzzleInTwoRoundsOfCategory).
 */
enum RoundNotMovableReason: string
{
    // The target is the competition the round is in already
    case SameCompetition = 'same_competition';
    // Participants are entered in the round (round entries or pairs/teams) - they belong to the competition; moving
    // them is a later step (docs/TODO.md)
    case HasEntries = 'has_entries';
    // The round's stopwatch is running - an organiser is timing it right now
    case StopwatchRunning = 'stopwatch_running';
    // The target is hidden as a draft (it, or its series) and the round has solving times: a draft must never get
    // linked times - every listing that reaches events through times would show its name. An empty round may go there
    case DraftTargetWithResults = 'draft_target_with_results';

    public function translationKey(): string
    {
        return 'restructure.move_round.refused.' . $this->value;
    }
}
