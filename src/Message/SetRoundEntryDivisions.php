<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Message;

use SpeedPuzzling\Web\Services\MessengerMiddleware\SerializedByLock;
use SpeedPuzzling\Web\Value\CompetitionParticipantsLock;
use SpeedPuzzling\Web\Value\EntryDivisionInput;

/**
 * Divisions of some entries of one round, all or nothing (`PUT /internal-api/rounds/{id}/entry-divisions` - the demo
 * fallback until the participants sheet has its Division column; shared-puzzles-and-divisions.md A10). A division must
 * be one the round uses. Entries left out keep theirs. The handler answers how many entries changed.
 */
readonly final class SetRoundEntryDivisions implements SerializedByLock
{
    /**
     * @param list<EntryDivisionInput> $entries
     */
    public function __construct(
        public string $competitionId,
        public string $roundId,
        public array $entries,
    ) {
    }

    public function lockKey(): string
    {
        return CompetitionParticipantsLock::key($this->competitionId);
    }
}
