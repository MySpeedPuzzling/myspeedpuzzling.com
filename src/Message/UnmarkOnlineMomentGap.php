<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Message;

use SpeedPuzzling\Web\Services\MessengerMiddleware\SerializedByLock;

/**
 * "Not a gap" (docs/features/online-competitions/README.md §5.9): the reviewer takes their G back on this finish moment
 * of the participant (the moment's event id), or confirmed that very moment - its verdict is removed. A moment without
 * one changes nothing. Never touches a confirmation or an official time.
 *
 * Under the moment's own lock, the same as MarkOnlineMomentGap's.
 */
readonly final class UnmarkOnlineMomentGap implements SerializedByLock
{
    public function __construct(
        // The competition the caller authorised - a round of another one is refused
        public string $competitionId,
        public string $roundId,
        public string $participantId,
        // The moment: the candidate's or the tap's event id
        public string $eventId,
        public string $actingPlayerId,
    ) {
    }

    public function lockKey(): string
    {
        return MarkOnlineMomentGap::momentLockKey($this->eventId);
    }
}
