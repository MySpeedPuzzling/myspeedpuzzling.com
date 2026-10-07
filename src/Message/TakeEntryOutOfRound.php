<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Message;

use SpeedPuzzling\Web\Services\MessengerMiddleware\SerializedByLock;
use SpeedPuzzling\Web\Value\CompetitionParticipantsLock;

/**
 * "Take out of this round" on the results desk - for an entry put into the round by mistake (a wrong advance, the
 * wrong group): a person leaves the solo round (their CompetitionParticipantRound goes; they stay in the event and its
 * other rounds), a pair/team is removed from its round together with its members' places in it. Refused
 * (OfficialResultsProtected) while the entry has a result or a qualified mark - official data never disappears as a
 * side effect. Under the event's participants lock, like every write that adds or moves round entries.
 * `competitionId` is the competition the caller authorised; a round of another one is refused.
 */
readonly final class TakeEntryOutOfRound implements SerializedByLock
{
    public function __construct(
        public string $competitionId,
        public string $roundId,
        // RoundEntryRef string: participant_round:<id> | team:<id>
        public string $entry,
    ) {
    }

    public function lockKey(): string
    {
        return CompetitionParticipantsLock::key($this->competitionId);
    }
}
