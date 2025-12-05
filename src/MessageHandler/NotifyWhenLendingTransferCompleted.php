<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\MessageHandler;

use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;
use Ramsey\Uuid\Uuid;
use Ramsey\Uuid\UuidInterface;
use SpeedPuzzling\Web\Entity\Notification;
use SpeedPuzzling\Web\Events\LendingTransferCompleted;
use SpeedPuzzling\Web\Repository\LentPuzzleTransferRepository;
use SpeedPuzzling\Web\Repository\PlayerRepository;
use SpeedPuzzling\Web\Value\NotificationType;
use SpeedPuzzling\Web\Value\TransferType;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
readonly final class NotifyWhenLendingTransferCompleted
{
    public function __construct(
        private LentPuzzleTransferRepository $transferRepository,
        private PlayerRepository $playerRepository,
        private EntityManagerInterface $entityManager,
        private ClockInterface $clock,
    ) {
    }

    public function __invoke(LendingTransferCompleted $event): void
    {
        $transfer = $this->transferRepository->get($event->transferId->toString());
        $playersToNotify = $this->determineRecipientsAndTypes($event);

        foreach ($playersToNotify as $notificationData) {
            $player = $this->playerRepository->get($notificationData['playerId']->toString());

            $notification = new Notification(
                Uuid::uuid7(),
                $player,
                $notificationData['type'],
                $this->clock->now(),
                null,
                $transfer,
            );

            $this->entityManager->persist($notification);
        }
    }

    /**
     * @return array<array{playerId: UuidInterface, type: NotificationType}>
     */
    private function determineRecipientsAndTypes(LendingTransferCompleted $event): array
    {
        $result = [];
        $acting = $event->actingPlayerId;

        switch ($event->transferType) {
            case TransferType::InitialLend:
                // Owner-initiated (lending to someone) → notify borrower
                if ($event->ownerPlayerId !== null && $acting->equals($event->ownerPlayerId) && $event->toPlayerId !== null) {
                    $result[] = [
                        'playerId' => $event->toPlayerId,
                        'type' => NotificationType::PuzzleLentToYou,
                    ];
                } elseif ($event->ownerPlayerId !== null && !$acting->equals($event->ownerPlayerId)) {
                // Borrower-initiated (borrowing from someone) → notify owner
                    $result[] = [
                        'playerId' => $event->ownerPlayerId,
                        'type' => NotificationType::PuzzleBorrowedFromYou,
                    ];
                }
                break;

            case TransferType::Pass:
                // Notify previous holder if they are not the actor
                if ($event->fromPlayerId !== null && !$acting->equals($event->fromPlayerId)) {
                    $result[] = [
                        'playerId' => $event->fromPlayerId,
                        'type' => NotificationType::PuzzlePassedFromYou,
                    ];
                }
                // Notify new holder if they are not the actor
                if ($event->toPlayerId !== null && !$acting->equals($event->toPlayerId)) {
                    $result[] = [
                        'playerId' => $event->toPlayerId,
                        'type' => NotificationType::PuzzlePassedToYou,
                    ];
                }
                // Notify owner if they are not the actor and not already notified as from/to
                if (
                    $event->ownerPlayerId !== null &&
                    !$acting->equals($event->ownerPlayerId) &&
                    ($event->fromPlayerId === null || !$event->ownerPlayerId->equals($event->fromPlayerId)) &&
                    ($event->toPlayerId === null || !$event->ownerPlayerId->equals($event->toPlayerId))
                ) {
                    $result[] = [
                        'playerId' => $event->ownerPlayerId,
                        'type' => NotificationType::YourPuzzleWasPassed,
                    ];
                }
                break;

            case TransferType::Return:
                // Holder returned the puzzle → notify owner
                if ($event->fromPlayerId !== null && $acting->equals($event->fromPlayerId) && $event->ownerPlayerId !== null) {
                    $result[] = [
                        'playerId' => $event->ownerPlayerId,
                        'type' => NotificationType::PuzzleReturnedToYou,
                    ];
                } elseif ($event->fromPlayerId !== null && !$acting->equals($event->fromPlayerId)) {
                // Owner took back the puzzle → notify holder
                    $result[] = [
                        'playerId' => $event->fromPlayerId,
                        'type' => NotificationType::PuzzleTakenBack,
                    ];
                }
                break;
        }

        return $result;
    }
}
