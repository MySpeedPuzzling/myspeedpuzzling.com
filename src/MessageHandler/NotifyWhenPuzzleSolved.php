<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\MessageHandler;

use Psr\Clock\ClockInterface;
use SpeedPuzzling\Web\Events\PuzzleSolved;
use SpeedPuzzling\Web\Query\GetSubscribedPlayers;
use SpeedPuzzling\Web\Repository\NotificationRepository;
use SpeedPuzzling\Web\Repository\PlayerRepository;
use SpeedPuzzling\Web\Repository\PuzzleSolvingTimeRepository;
use SpeedPuzzling\Web\Value\NotificationType;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
readonly final class NotifyWhenPuzzleSolved
{
    public function __construct(
        private PuzzleSolvingTimeRepository $puzzleSolvingTimeRepository,
        private PlayerRepository $playerRepository,
        private GetSubscribedPlayers $getSubscribedPlayers,
        private NotificationRepository $notificationRepository,
        private ClockInterface $clock,
    ) {
    }

    public function __invoke(PuzzleSolved $event): void
    {
        $solvingTime = $this->puzzleSolvingTimeRepository->get($event->puzzleSolvingTimeId->toString());

        // Players whose followers get notified - private profiles notify nobody
        $solvingPlayerIds = [];

        if ($solvingTime->team === null) {
            if ($solvingTime->player->isPrivate === false) {
                $solvingPlayerIds[] = $solvingTime->player->id->toString();
            }
        } else {
            foreach ($solvingTime->team->puzzlers as $puzzler) {
                if ($puzzler->playerId === null) {
                    continue;
                }

                if ($this->playerRepository->get($puzzler->playerId)->isPrivate === false) {
                    $solvingPlayerIds[] = $puzzler->playerId;
                }
            }
        }

        if ($solvingPlayerIds === []) {
            return;
        }

        // One subscriber gets one notification, however many of the team they follow
        $subscribedPlayerIds = $this->getSubscribedPlayers->ofPlayers($solvingPlayerIds);

        if ($subscribedPlayerIds === []) {
            return;
        }

        $this->notificationRepository->addForSolvingTime(
            $subscribedPlayerIds,
            NotificationType::SubscribedPlayerAddedTime,
            $solvingTime->id,
            $this->clock->now(),
        );
    }
}
