<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\MessageHandler;

use SpeedPuzzling\Web\Exceptions\PlayerNotFound;
use SpeedPuzzling\Web\Message\UnsubscribeFromDigestEmails;
use SpeedPuzzling\Web\Repository\PlayerRepository;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * One-click unsubscribe from the unread-messages digest - switches off email_notifications_enabled only (the one
 * setting the digest reads), the "Your results" e-mails and the newsletter stay as they are.
 */
#[AsMessageHandler]
readonly final class UnsubscribeFromDigestEmailsHandler
{
    public function __construct(
        private PlayerRepository $playerRepository,
    ) {
    }

    /**
     * @throws PlayerNotFound
     */
    public function __invoke(UnsubscribeFromDigestEmails $message): void
    {
        $this->playerRepository->get($message->playerId)->changeEmailNotificationsEnabled(false);
    }
}
