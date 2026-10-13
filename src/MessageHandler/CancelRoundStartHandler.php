<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\MessageHandler;

use Psr\Clock\ClockInterface;
use SpeedPuzzling\Web\Exceptions\RoundStopwatchRefused;
use SpeedPuzzling\Web\Message\CancelRoundStart;
use SpeedPuzzling\Web\Repository\CompetitionRoundRepository;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * Calls a countdown off - only before its moment, a round that runs is never reset by a late cancel
 * (CompetitionRound::cancelScheduledStart()). The caller checked the rights (COMPETITION_EDIT); the acting player is not
 * stored. Publishes nothing: the controller tells the open pages once the dispatch returned, after the commit
 * (RoundStopwatchLiveUpdates).
 */
#[AsMessageHandler]
readonly final class CancelRoundStartHandler
{
    public function __construct(
        private CompetitionRoundRepository $competitionRoundRepository,
        private ClockInterface $clock,
    ) {
    }

    /**
     * @throws RoundStopwatchRefused
     */
    public function __invoke(CancelRoundStart $message): void
    {
        $round = $this->competitionRoundRepository->get($message->roundId);

        $round->cancelScheduledStart($this->clock->now());
    }
}
