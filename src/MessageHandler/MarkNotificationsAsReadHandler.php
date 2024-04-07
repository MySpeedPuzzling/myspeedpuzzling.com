<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\MessageHandler;

use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Exceptions\PlayerNotFound;
use SpeedPuzzling\Web\Message\MarkNotificationsAsRead;
use SpeedPuzzling\Web\Repository\NotificationRepository;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
readonly final class MarkNotificationsAsReadHandler
{
    public function __construct(
        private NotificationRepository $notificationRepository,
    ) {
    }

    /**
     * @throws PlayerNotFound
     */
    public function __invoke(MarkNotificationsAsRead $message): void
    {
        if (Uuid::isValid($message->playerId) === false) {
            throw new PlayerNotFound();
        }

        $this->notificationRepository->markNotificationAsReadForPlayer($message->playerId);
    }
}
