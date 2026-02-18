<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\MessageHandler;

use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Entity\Notification;
use SpeedPuzzling\Web\Events\TransactionCompleted;
use SpeedPuzzling\Web\Repository\PlayerRepository;
use SpeedPuzzling\Web\Repository\SoldSwappedItemRepository;
use SpeedPuzzling\Web\Value\NotificationType;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
readonly final class NotifyWhenTransactionCompleted
{
    public function __construct(
        private SoldSwappedItemRepository $soldSwappedItemRepository,
        private PlayerRepository $playerRepository,
        private EntityManagerInterface $entityManager,
        private ClockInterface $clock,
    ) {
    }

    public function __invoke(TransactionCompleted $event): void
    {
        // Ratings only work with registered buyers
        if ($event->buyerPlayerId === null) {
            return;
        }

        $soldSwappedItem = $this->soldSwappedItemRepository->get($event->soldSwappedItemId->toString());

        // Notify the seller
        $seller = $this->playerRepository->get($event->sellerId->toString());
        $sellerNotification = new Notification(
            Uuid::uuid7(),
            $seller,
            NotificationType::RateYourTransaction,
            $this->clock->now(),
            targetSoldSwappedItem: $soldSwappedItem,
        );
        $this->entityManager->persist($sellerNotification);

        // Notify the buyer
        $buyer = $this->playerRepository->get($event->buyerPlayerId->toString());
        $buyerNotification = new Notification(
            Uuid::uuid7(),
            $buyer,
            NotificationType::RateYourTransaction,
            $this->clock->now(),
            targetSoldSwappedItem: $soldSwappedItem,
        );
        $this->entityManager->persist($buyerNotification);
    }
}
