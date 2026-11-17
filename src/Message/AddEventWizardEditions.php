<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Message;

use SpeedPuzzling\Web\Services\MessengerMiddleware\SerializedByLock;

/**
 * The event creation wizard's "Dates + each date's round" (W-creation-wizard-research.md §3.7, W3): the dates of a series
 * (AddEditions - idempotent ids from the preview) and one round per date it creates, in one transaction under the
 * series' lock (`series-editions-*`, the key AddEditions takes - the nested dispatch passes through). A date AddEditions
 * skips (its id exists - the form sent again - or its day is taken) gets no round, so re-sending creates nothing twice.
 * An orchestration of existing messages, no domain concept of its own.
 */
readonly final class AddEventWizardEditions implements SerializedByLock
{
    /**
     * @param list<AddCompetitionRound> $rounds at most one per edition, each of an edition of $editions - empty = "No
     *                                          rounds - just dates on the calendar"
     */
    public function __construct(
        public AddEditions $editions,
        public array $rounds,
    ) {
        $editionIds = [];
        foreach ($editions->editions as $edition) {
            $editionIds[$edition->competitionId->toString()] = true;
        }

        $roundEditions = [];
        foreach ($rounds as $round) {
            $competitionId = strtolower($round->competitionId);

            if (isset($editionIds[$competitionId]) === false || isset($roundEditions[$competitionId])) {
                throw new \InvalidArgumentException('Every round must belong to one new edition, one round each.');
            }

            $roundEditions[$competitionId] = true;
        }
    }

    public function lockKey(): string
    {
        return $this->editions->lockKey();
    }
}
