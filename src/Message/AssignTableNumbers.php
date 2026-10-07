<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Message;

use SpeedPuzzling\Web\Services\MessengerMiddleware\SerializedByLock;
use SpeedPuzzling\Web\Value\CompetitionParticipantsLock;

/**
 * Seating in one write: table numbers (1..9999, null = none) for any entries of a round, checked as a whole - every
 * entry of this round, each listed once, and no number shared by two entries of the round afterwards (entries not
 * listed keep theirs). Every assignment carries `from` - the number the device last saw: an entry whose number is
 * neither `from` nor the new one any more was changed by somebody else meanwhile (`changed_meanwhile`, with the
 * `current` number). Anything wrong refuses everything (InvalidTableNumbers) - a renumbering is never half applied and
 * never overwrites another organiser's numbers; the page fetches the round again and the organiser decides anew.
 * The handler answers the refs of the entries whose number changed.
 */
readonly final class AssignTableNumbers implements SerializedByLock
{
    public function __construct(
        public string $competitionId,
        public string $roundId,
        /** @var list<array{entry: string, from: null|int, number: null|int}> entry = RoundEntryRef string */
        public array $assignments,
    ) {
    }

    public function lockKey(): string
    {
        return CompetitionParticipantsLock::key($this->competitionId);
    }
}
