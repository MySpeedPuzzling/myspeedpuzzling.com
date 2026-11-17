<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Message;

use SpeedPuzzling\Web\Services\MessengerMiddleware\SerializedByLock;
use SpeedPuzzling\Web\Value\CompetitionParticipantsLock;

/**
 * The event creation wizard's "Puzzle & format" step (W-creation-wizard-research.md §3.4) in one transaction, as an
 * orchestration of the existing messages in this order:
 *
 * 1. $round - EditCompetitionRound: who competes, team size, time limit (and the generated name following the category);
 * 2. $puzzles - AddPuzzleToCompetitionRound each (a secret one with the automatic reveal its page showed -
 *    `shownAutomaticRevealAt`, the safety contract of that message);
 * 3. $reveals - ChangeRoundPuzzleReveal of a puzzle just added (when I start the stopwatch / manually);
 * 4. $format - EditCompetitionRound with the round's puzzle format (pick K of N) once its puzzles exist, every other
 *    field kept.
 *
 * Whatever any of them refuses (the one-round-per-category invariant, the reveal moment moved meanwhile, K out of
 * range...) rolls everything back - no half-written round. Takes the event's participants lock like
 * EditCompetitionRound; the nested ones pass through it (LockUntilCommittedMiddleware).
 */
readonly final class SetUpEventWizardRound implements SerializedByLock
{
    /**
     * @param list<AddPuzzleToCompetitionRound> $puzzles
     * @param list<ChangeRoundPuzzleReveal> $reveals
     */
    public function __construct(
        public string $competitionId,
        public EditCompetitionRound $round,
        public array $puzzles = [],
        public array $reveals = [],
        public null|EditCompetitionRound $format = null,
    ) {
        if ($round->competitionId !== $competitionId || ($format !== null && $format->competitionId !== $competitionId)) {
            throw new \InvalidArgumentException('The round must belong to the event locked.');
        }

        foreach ($puzzles as $puzzle) {
            if ($puzzle->roundId !== $round->roundId) {
                throw new \InvalidArgumentException('The puzzles must go into the round set up.');
            }
        }

        if ($format !== null && $format->roundId !== $round->roundId) {
            throw new \InvalidArgumentException('The format must be the round set up.');
        }
    }

    public function lockKey(): string
    {
        return CompetitionParticipantsLock::key($this->competitionId);
    }
}
