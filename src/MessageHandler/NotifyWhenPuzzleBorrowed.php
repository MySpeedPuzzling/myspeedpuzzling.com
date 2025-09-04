<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\MessageHandler;

use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Entity\Notification;
use SpeedPuzzling\Web\Events\PuzzleBorrowed;
use SpeedPuzzling\Web\Repository\PlayerRepository;
use SpeedPuzzling\Web\Repository\PuzzleRepository;
use SpeedPuzzling\Web\Value\NotificationType;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
readonly final class NotifyWhenPuzzleBorrowed
{
    public function __construct(
        private PlayerRepository $playerRepository,
        private PuzzleRepository $puzzleRepository,
        private EntityManagerInterface $entityManager,
        private ClockInterface $clock,
    ) {
    }

    public function __invoke(PuzzleBorrowed $event): void
    {
        // Skip if borrowing from/to non-registered person
        if ($event->nonRegisteredPersonName !== null) {
            return;
        }

        $fromPlayer = $this->playerRepository->get($event->fromPlayerId->toString());
        $toPlayer = $this->playerRepository->get($event->toPlayerId->toString());
        $puzzle = $this->puzzleRepository->get($event->puzzleId->toString());

        $notificationType = $event->borrowedFrom
            ? NotificationType::PuzzleBorrowedFrom
            : NotificationType::PuzzleBorrowedTo;

        // Notify the other party about the borrowing
        $targetPlayer = $event->borrowedFrom ? $fromPlayer : $toPlayer;

        $notification = new Notification(
            Uuid::uuid7(),
            $targetPlayer,
            $notificationType,
            $this->clock->now(),
            null, // puzzleSolvingTime
            $puzzle,
            $event->borrowedFrom ? $toPlayer : $fromPlayer, // otherPlayer
        );

        $this->entityManager->persist($notification);
        $this->entityManager->flush();
    }
}
