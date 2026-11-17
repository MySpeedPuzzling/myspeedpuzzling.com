<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Message;

use SpeedPuzzling\Web\Services\MessengerMiddleware\SerializedByLock;
use SpeedPuzzling\Web\Value\CompetitionParticipantsLock;

/**
 * The event creation wizard's "Structure" step on the several-rounds path (W-creation-wizard-research.md §3.5, §4) in
 * one transaction, as an orchestration of the existing messages in this order:
 *
 * 0. the event's rounds are checked again under the lock when anything existing changes ($replaceStandings, $deletes,
 *    $edits): an entry, a linked result or a stopwatch run since the page was loaded refuses everything
 *    (EventWizardStructureLocked);
 * 1. $replaceStandings - DeleteCompetitionStanding for every standing the event has now, read under the lock (a
 *    template replacing every round: they would be left without rounds);
 * 2. $deletes - DeleteCompetitionRound (refusing a round with results and a delete that would reveal a secret puzzle);
 * 3. $edits - EditCompetitionRound (name, start, who competes, time limit from the schedule table);
 * 4. $adds - AddCompetitionRound (a template's rounds, the table's new rows);
 * 5. $advancementSteps - CreateCompetitionAdvancementStep per stage arrow (toolkit only, W7 - checked against the
 *    rounds just added by AdvancementStepGraph);
 * 6. $standings - CreateCompetitionStanding per category ("add up the times per category", toolkit only, W7);
 * 7. $joinStandings (the schedule table) - the rounds of $adds join the standings that add up every round of their
 *    category (StandingsJoiningNewRounds, decided on the event as it was before this step - EditCompetitionStanding).
 *
 * Whatever any of them refuses rolls everything back - never half a structure. Takes the event's participants lock
 * (the round deletes and edits, the steps and standings do); the nested ones pass through it
 * (LockUntilCommittedMiddleware).
 */
readonly final class SetUpEventWizardStructure implements SerializedByLock
{
    /**
     * @param list<DeleteCompetitionRound> $deletes
     * @param list<EditCompetitionRound> $edits
     * @param list<AddCompetitionRound> $adds
     * @param list<CreateCompetitionAdvancementStep> $advancementSteps
     * @param list<CreateCompetitionStanding> $standings
     */
    public function __construct(
        public string $competitionId,
        public bool $replaceStandings = false,
        public array $deletes = [],
        public array $edits = [],
        public array $adds = [],
        public array $advancementSteps = [],
        public array $standings = [],
        public bool $joinStandings = false,
    ) {
        $competitionId = strtolower($competitionId);

        foreach ([...$deletes, ...$edits, ...$adds, ...$advancementSteps, ...$standings] as $message) {
            if (strtolower($message->competitionId) !== $competitionId) {
                throw new \InvalidArgumentException('Every change must belong to the event locked.');
            }
        }
    }

    public function lockKey(): string
    {
        return CompetitionParticipantsLock::key($this->competitionId);
    }
}
