<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\MessageHandler;

use Doctrine\ORM\EntityManagerInterface;
use SpeedPuzzling\Web\Exceptions\CanNotResetStopwatchForDifferentPlayer;
use SpeedPuzzling\Web\Message\ResetStopwatch;
use SpeedPuzzling\Web\Repository\PlayerRepository;
use SpeedPuzzling\Web\Repository\StopwatchRepository;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
readonly final class ResetStopwatchHandler
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private PlayerRepository $playerRepository,
        private StopwatchRepository $stopwatchRepository,
    ) {
    }

    /**
     * @throws StopwatchNotFound
     * @throws CanNotResetStopwatchForDifferentPlayer
     */
    public function __invoke(ResetStopwatch $message): void
    {
        $player = $this->playerRepository->getByUserIdCreateIfNotExists($message->userId);
        $stopwatch = $this->stopwatchRepository->get($message->stopwatchId->toString());

        if ($stopwatch->player->userId !== $player->userId) {
            throw new CanNotResetStopwatchForDifferentPlayer();
        }

        $this->entityManager->remove($stopwatch);
    }
}
