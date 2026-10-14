<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Message;

use SpeedPuzzling\Web\Services\MessengerMiddleware\SerializedByLock;

/**
 * The reviewer pressed G (docs/features/online-competitions/README.md §5.9): the photo at this finish moment of the
 * participant - a raise of the hands (`finish.candidate`) or a Finished tap (`finish.button`), the moment's event id -
 * shows a gap. Stored as the moment's verdict (OnlineReviewMomentVerdict); marking it again changes nothing. Never
 * touches a suggestion, a confirmation or an official time.
 *
 * Under the moment's own lock: two reviewers pressing G on the same moment at once write one row, never two.
 */
readonly final class MarkOnlineMomentGap implements SerializedByLock
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
        return self::momentLockKey($this->eventId);
    }

    /**
     * The lock of one finish moment's verdict - marking and "Not a gap" take turns under it
     */
    public static function momentLockKey(string $eventId): string
    {
        return 'online-moment-verdict-' . strtolower($eventId);
    }
}
