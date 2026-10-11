<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Services\Xp;

use Psr\Log\LoggerInterface;
use SpeedPuzzling\Web\Message\AwardXpForSolvingTime;
use SpeedPuzzling\Web\Message\RecalculateBadgesForPlayer;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\TransportNamesStamp;

/**
 * The recap after a saved time shows the XP it earned and the achievements it brought at its first render: right after
 * the save committed, the saving player's achievement evaluation and the XP award run in this request, achievements
 * first (the recap credits the tiers stamped after the save). Measured 2026-10-11 on the production copy, each in its
 * transaction: 3-9 ms for a typical player, at most 90 ms for the heaviest (2,300 results) - far below the 300 ms that
 * would justify a page of their own, and a queue round trip used to leave the recap polling.
 *
 * The messages the save queued stay the safety net (other registered members of a pair/team, a failure here); they
 * find nothing left to do - both are recomputations from the records. A failure never fails the save: the recap
 * falls back to polling.
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
            $this->logger->warning('The progression of a saved time could not be settled before its recap - the queued messages will', [
                'exception' => $e,
                'player_id' => $playerId,
                'solving_time_id' => $solvingTimeId,
            ]);
        }
    }
}
