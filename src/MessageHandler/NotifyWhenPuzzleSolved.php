<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\MessageHandler;

use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Entity\Notification;
use SpeedPuzzling\Web\Events\PuzzleSolved;
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
        private EntityManagerInterface $entityManager,
        private ClockInterface $clock,
    ) {
    }

    public function __invoke(PuzzleSolved $event): void
    {
        $solvingTime = $this->puzzleSolvingTimeRepository->get($event->puzzleSolvingTimeId->toString());

        if ($solvingTime->team === null) {
            // Skip notifying if the player has private profile
            if ($solvingTime->player->isPrivate === true) {
                return;
            }

            $subscribedPlayers = $this->playerRepository->findPlayersByFavoriteUuid($solvingTime->player->id->toString());
        } else {
            $subscribedPlayers = [];

            // Collect all favorites from all puzzlers from team
            foreach ($solvingTime->team->puzzlers as $puzzler) {
                if ($puzzler->playerId !== null) {
                    $teamPuzzler = $this->playerRepository->get($puzzler->playerId);

                    // Skip notifying if the player has private profile
                    if ($teamPuzzler->isPrivate === true) {
                        continue;
                    }

                    foreach ($this->playerRepository->findPlayersByFavoriteUuid($puzzler->playerId) as $subscribedPlayer) {
                        $subscribedPlayers[] = $subscribedPlayer;
                    }
                }
            }

            // Deduplicate, so one subscriber does not get multiple notifications
            $playersAboutToBeNotified = [];
            foreach ($subscribedPlayers as $key => $player) {
                $playerId = $player->id->toString();

                if (isset($playersAboutToBeNotified[$playerId])) {
                    unset($subscribedPlayers[$key]);
                    continue;
                }

                $playersAboutToBeNotified[$playerId] = true;
            }
        }

        foreach ($subscribedPlayers as $targetPlayer) {
            $notification = new Notification(
                Uuid::uuid7(),
                $targetPlayer,
                NotificationType::SubscribedPlayerAddedTime,
                $this->clock->now(),
                $solvingTime,
            );

            $this->entityManager->persist($notification);
        }
    }
}
