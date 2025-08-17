<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\MessageHandler;

use SpeedPuzzling\Web\Exceptions\CanNotModifyOtherPlayersTime;
use SpeedPuzzling\Web\Exceptions\PuzzleNotFound;
use SpeedPuzzling\Web\Exceptions\StopwatchAlreadyFinished;
use SpeedPuzzling\Web\Exceptions\StopwatchCouldNotBeFinished;
use SpeedPuzzling\Web\Message\FinishStopwatch;
use SpeedPuzzling\Web\Repository\PlayerRepository;
use SpeedPuzzling\Web\Repository\PuzzleRepository;
use SpeedPuzzling\Web\Repository\StopwatchRepository;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
readonly final class FinishStopwatchHandler
{
    public function __construct(
        private PlayerRepository $playerRepository,
        private StopwatchRepository $stopwatchRepository,
        private PuzzleRepository $puzzleRepository,
    ) {
    }

    /**
     * @throws StopwatchCouldNotBeFinished
     * @throws StopwatchAlreadyFinished
     * @throws CanNotModifyOtherPlayersTime
     * @throws PuzzleNotFound
     */
    public function __invoke(FinishStopwatch $message): void
    {
        $currentPlayer = $this->playerRepository->getByUserIdCreateIfNotExists($message->currentUserId);
        $stopwatch = $this->stopwatchRepository->get($message->stopwatchId);

        if ($currentPlayer->id->equals($stopwatch->player->id) === false) {
            throw new CanNotModifyOtherPlayersTime();
        }

        $puzzle = $this->puzzleRepository->get($message->puzzleId);

        $stopwatch->finish($puzzle);
    }
}
