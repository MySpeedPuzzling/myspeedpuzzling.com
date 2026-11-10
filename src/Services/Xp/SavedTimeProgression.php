<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Services\Xp;

use Psr\Log\LoggerInterface;
use SpeedPuzzling\Web\Message\AwardXpForSolvingTime;
use SpeedPuzzling\Web\Message\RecalculateBadgesForPlayer;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\TransportNamesStamp;

/**
 * Settles a saved time's progression for one participant right now: their achievement evaluation, then the solve's
 * XP award (achievements first - the recap credits the tiers stamped after the save), each in its own transaction
 * under PlayerProgressionLock.
 *
 * The save does not call it (docs/features/xp-levels/README.md §Surfaces): the messages the save queued, handled by
 * the consumer the commit wakes, usually finish before the recap renders - the save used to wait for the consumer's
 * lock (~31 ms a save on average, Sentry 2026-11) and then found nothing to do. Its one caller is the recap
 * (XpRecapCelebration::settle), when the queue was not done at the first render: the wait, if any, happens there,
 * with the page already painted. Both writers are recomputations from the records, so whoever comes second changes
 * nothing (SavedTimeProgressionEquivalenceTest).
 *
 * The "XP lines exist, so the achievements were evaluated" shortcut of the recap holds because this runs the badges
 * first and the single in-order consumer gets the badge message first. Several consumers would break it for the
 * queue path.
 *
 * A failure never fails the caller: the recap falls back to polling, the queued messages stay.
 */
readonly final class SavedTimeProgression
{
    public function __construct(
        private MessageBusInterface $commandBus,
        private LoggerInterface $logger,
    ) {
    }

    public function settleNow(string $playerId, string $solvingTimeId): void
    {
        try {
            $this->commandBus->dispatch(new RecalculateBadgesForPlayer($playerId), [new TransportNamesStamp(['sync'])]);
            $this->commandBus->dispatch(new AwardXpForSolvingTime($solvingTimeId), [new TransportNamesStamp(['sync'])]);
        } catch (\Throwable $e) {
            $this->logger->warning('The progression of a saved time could not be settled for its recap - the queued messages will', [
                'exception' => $e,
                'player_id' => $playerId,
                'solving_time_id' => $solvingTimeId,
            ]);
        }
    }
}
