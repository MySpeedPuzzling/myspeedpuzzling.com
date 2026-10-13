<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\MessageHandler;

use Psr\Clock\ClockInterface;
use SpeedPuzzling\Web\Exceptions\RoundStopwatchRefused;
use SpeedPuzzling\Web\Message\StartRoundStopwatch;
use SpeedPuzzling\Web\Repository\CompetitionRoundRepository;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * Starts the round's stopwatch now, or resumes a stopped one (CompetitionRound::startStopwatch()). Publishes nothing:
 * the controller tells the open pages once the dispatch returned, after the commit (RoundStopwatchLiveUpdates).
 */
#[AsMessageHandler]
readonly final class StartRoundStopwatchHandler
{
    public function __construct(
        private CompetitionRoundRepository $competitionRoundRepository,
        private ClockInterface $clock,
    ) {
    }

    /**
     * @throws RoundStopwatchRefused
     */
    public function __invoke(StartRoundStopwatch $message): void
    {
        $round = $this->competitionRoundRepository->get($message->roundId);

        $round->startStopwatch($this->clock->now());
    }
}
