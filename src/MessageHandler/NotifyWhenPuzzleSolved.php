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
    function __construct(
        private PuzzleSolvingTimeRepository $puzzleSolvingTimeRepository,
        private PlayerRepository $playerRepository,
        private EntityManagerInterface $entityManager,
        private ClockInterface $clock,
    ) {
    }

    public function __invoke(PuzzleSolved $event): void
    {
        $solvingTime = $this->puzzleSolvingTimeRepository->get($event->puzzleSolvingTimeId->toString());
        $players = $this->playerRepository->findPlayersByFavoriteUuid($solvingTime->player->id->toString());

        foreach ($players as $targetPlayer) {
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
