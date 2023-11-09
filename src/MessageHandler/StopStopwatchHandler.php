<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\MessageHandler;

use SpeedPuzzling\Web\Exceptions\StopwatchCouldNotBePaused;
use SpeedPuzzling\Web\Exceptions\StopwatchNotFound;
use SpeedPuzzling\Web\Message\StopStopwatch;
use SpeedPuzzling\Web\Repository\StopwatchRepository;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
readonly final class StopStopwatchHandler
{
    public function __construct(
        private StopwatchRepository $stopwatchRepository,
    ) {
    }

    /**
     * @throws StopwatchNotFound
     * @throws StopwatchCouldNotBePaused
     */
    public function __invoke(StopStopwatch $message): void
    {
        $stopwatch = $this->stopwatchRepository->get($message->stopwatchId->toString());

        $stopwatch->pause(new \DateTimeImmutable());
    }
}
