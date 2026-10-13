<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\MessageHandler;

use SpeedPuzzling\Web\Message\ResetRoundStopwatch;
use SpeedPuzzling\Web\Repository\CompetitionRoundRepository;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * Clears the round's stopwatch - never started again. Publishes nothing: the controller tells the open pages once the
 * dispatch returned, after the commit (RoundStopwatchLiveUpdates).
 */
#[AsMessageHandler]
readonly final class ResetRoundStopwatchHandler
{
    public function __construct(
        private CompetitionRoundRepository $competitionRoundRepository,
    ) {
    }

    public function __invoke(ResetRoundStopwatch $message): void
    {
        $round = $this->competitionRoundRepository->get($message->roundId);

        $round->resetStopwatch();
    }
}
