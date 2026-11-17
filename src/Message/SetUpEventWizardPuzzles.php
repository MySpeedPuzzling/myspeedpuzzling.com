<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Message;

use SpeedPuzzling\Web\Services\MessengerMiddleware\SerializedByLock;
use SpeedPuzzling\Web\Value\CompetitionParticipantsLock;

/**
 * The event creation wizard's "Puzzles" grid on the several-rounds path (W-creation-wizard-research.md §3.6, §4): one
 * SetUpEventWizardRound per row that changes (W1's composite - its puzzles with the automatic reveal each row showed,
 * the reveal switched, the round's format) in one transaction - the one-puzzle-per-category invariant, a moved reveal or
 * a K out of range refused in any row rolls every row back. Takes the event's participants lock; the nested ones pass
 * through it (LockUntilCommittedMiddleware).
 */
readonly final class SetUpEventWizardPuzzles implements SerializedByLock
{
    /**
     * @param list<SetUpEventWizardRound> $rounds
     */
    public function __construct(
        public string $competitionId,
        public array $rounds,
    ) {
        foreach ($rounds as $round) {
            if (strtolower($round->competitionId) !== strtolower($competitionId)) {
                throw new \InvalidArgumentException('Every round must belong to the event locked.');
            }
        }
    }

    public function lockKey(): string
    {
        return CompetitionParticipantsLock::key($this->competitionId);
    }
}
