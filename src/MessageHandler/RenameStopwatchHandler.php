<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\MessageHandler;

use SpeedPuzzling\Web\Message\RenameStopwatch;
use SpeedPuzzling\Web\Repository\StopwatchRepository;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
readonly final class RenameStopwatchHandler
{
    public function __construct(
        private StopwatchRepository $stopwatchRepository,
    ) {
    }

    public function __invoke(RenameStopwatch $message): void
    {
        $stopwatch = $this->stopwatchRepository->get($message->stopwatchId->toString());

        $stopwatch->rename($message->name);
    }
}
