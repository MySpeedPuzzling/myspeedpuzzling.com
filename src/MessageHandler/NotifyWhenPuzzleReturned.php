<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\MessageHandler;

use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Entity\Notification;
use SpeedPuzzling\Web\Events\PuzzleReturned;
use SpeedPuzzling\Web\Repository\PlayerRepository;
use SpeedPuzzling\Web\Repository\PuzzleRepository;
use SpeedPuzzling\Web\Value\NotificationType;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
readonly final class NotifyWhenPuzzleReturned
{
    public function __construct(
        private PlayerRepository $playerRepository,
        private PuzzleRepository $puzzleRepository,
        private EntityManagerInterface $entityManager,
        private ClockInterface $clock,
    ) {
    }

    public function __invoke(PuzzleReturned $event): void
    {
        // Skip if borrower is non-registered
        if ($event->borrowerId === null) {
            return;
        }

        $owner = $this->playerRepository->get($event->ownerId->toString());
        $borrower = $this->playerRepository->get($event->borrowerId->toString());
        $initiator = $this->playerRepository->get($event->initiatorId->toString());
        $puzzle = $this->puzzleRepository->get($event->puzzleId->toString());

        // Notify the other party about the return
        $targetPlayer = $initiator->id->toString() === $owner->id->toString() ? $borrower : $owner;

        $notification = new Notification(
            Uuid::uuid7(),
            $targetPlayer,
            NotificationType::PuzzleReturned,
            $this->clock->now(),
            null, // puzzleSolvingTime
            $puzzle,
            $initiator, // otherPlayer who initiated the return
        );

        $this->entityManager->persist($notification);
        $this->entityManager->flush();
    }
}