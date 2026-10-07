<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Message;

use SpeedPuzzling\Web\Services\MessengerMiddleware\SerializedByLock;
use SpeedPuzzling\Web\Value\CompetitionParticipantsLock;

/**
 * Seating in one write: table numbers (1..9999, null = none) for any entries of a round, checked as a whole - every
 * entry of this round, each listed once, and no number shared by two entries of the round afterwards (entries not
 * listed keep theirs). Anything wrong refuses everything (InvalidTableNumbers). The handler answers the refs of the
 * entries whose number changed.
 */
readonly final class AssignTableNumbers implements SerializedByLock
{
    public function __construct(
        public string $competitionId,
        public string $roundId,
        /** @var list<array{entry: string, number: null|int}> entry = RoundEntryRef string */
        public array $assignments,
    ) {
    }

    public function lockKey(): string
    {
        return CompetitionParticipantsLock::key($this->competitionId);
    }
}
