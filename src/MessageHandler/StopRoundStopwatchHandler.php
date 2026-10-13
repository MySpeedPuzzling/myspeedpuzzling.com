<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\MessageHandler;

use Psr\Clock\ClockInterface;
use SpeedPuzzling\Web\Message\StopRoundStopwatch;
use SpeedPuzzling\Web\Repository\CompetitionRoundRepository;
use SpeedPuzzling\Web\Repository\OnlineRoundRepository;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * Stops the round's stopwatch - the end of an online round ("End now", stored rounded up to the next whole second:
 * CompetitionRound::endStopwatch()), a pause in person (CompetitionRound::stopStopwatch()). Publishes nothing: the
 * controller tells the open pages once the dispatch returned, after the commit (RoundStopwatchLiveUpdates).
 */
#[AsMessageHandler]
readonly final class StopRoundStopwatchHandler
{
    public function __construct(
        private CompetitionRoundRepository $competitionRoundRepository,
        private OnlineRoundRepository $onlineRoundRepository,
        private ClockInterface $clock,
    ) {
    }

    public function __invoke(StopRoundStopwatch $message): void
    {
        $round = $this->competitionRoundRepository->get($message->roundId);

        if ($this->onlineRoundRepository->ofRound($round->id->toString()) !== null) {
            $round->endStopwatch($this->clock->now());

            return;
        }

        $round->stopStopwatch($this->clock->now());
    }
}
