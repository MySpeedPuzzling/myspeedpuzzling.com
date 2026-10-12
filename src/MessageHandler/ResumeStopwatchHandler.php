<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\MessageHandler;

use Psr\Clock\ClockInterface;
use SpeedPuzzling\Web\Exceptions\StopwatchCouldNotBeResumed;
use SpeedPuzzling\Web\Exceptions\StopwatchNotFound;
use SpeedPuzzling\Web\Message\ResumeStopwatch;
use SpeedPuzzling\Web\Repository\StopwatchRepository;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
readonly final class ResumeStopwatchHandler
{
    public function __construct(
        private StopwatchRepository $stopwatchRepository,
        private ClockInterface $clock,
    ) {
    }

    /**
     * @throws StopwatchNotFound
     * @throws StopwatchCouldNotBeResumed
     */
    public function __invoke(ResumeStopwatch $message): void
    {
        $stopwatch = $this->stopwatchRepository->get($message->stopwatchId->toString());

        $tappedAt = $this->clock->now()->modify(sprintf('-%d milliseconds', $message->tappedMillisecondsAgo));

        $stopwatch->resume($tappedAt);
    }
}
