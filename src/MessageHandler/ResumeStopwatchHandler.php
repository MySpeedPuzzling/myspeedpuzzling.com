<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\MessageHandler;

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
    ) {
    }

    /**
     * @throws StopwatchNotFound
     * @throws StopwatchCouldNotBeResumed
     */
    public function __invoke(ResumeStopwatch $message): void
    {
        $stopwatch = $this->stopwatchRepository->get($message->stopwatchId->toString());

        $stopwatch->resume(new \DateTimeImmutable());
    }
}
