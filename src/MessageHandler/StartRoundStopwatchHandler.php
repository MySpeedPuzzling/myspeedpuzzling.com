<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\MessageHandler;

use Psr\Clock\ClockInterface;
use SpeedPuzzling\Web\Exceptions\RoundStopwatchRefused;
use SpeedPuzzling\Web\Message\StartRoundStopwatch;
use SpeedPuzzling\Web\Repository\CompetitionRoundRepository;
use SpeedPuzzling\Web\Repository\OnlineRoundRepository;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * Starts the round's stopwatch now, or resumes a stopped one (CompetitionRound::startStopwatch()) - in person only: a
 * round with online timing that ended is refused (RoundStopwatchRefusal::EndedUseReset, a Reset runs it again).
 * Publishes nothing: the controller tells the open pages once the dispatch returned, after the commit
 * (RoundStopwatchLiveUpdates).
 */
#[AsMessageHandler]
readonly final class StartRoundStopwatchHandler
{
    public function __construct(
        private CompetitionRoundRepository $competitionRoundRepository,
        private OnlineRoundRepository $onlineRoundRepository,
        private ClockInterface $clock,
    ) {
    }

    /**
     * @throws RoundStopwatchRefused
     */
    public function __invoke(StartRoundStopwatch $message): void
    {
        $round = $this->competitionRoundRepository->get($message->roundId);

        $round->startStopwatch(
            $this->clock->now(),
            onlineTiming: $this->onlineRoundRepository->ofRound($round->id->toString()) !== null,
        );
    }
}
