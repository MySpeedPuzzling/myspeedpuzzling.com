<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\MessageHandler;

use SpeedPuzzling\Web\Exceptions\PlayerNotFound;
use SpeedPuzzling\Web\Message\UnsubscribeFromResultEmails;
use SpeedPuzzling\Web\Repository\PlayerRepository;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * One-click unsubscribe from the "Your results" e-mails - switches off result_emails_enabled only, the chat
 * digest and the newsletter stay as they are.
 */
#[AsMessageHandler]
readonly final class UnsubscribeFromResultEmailsHandler
{
    public function __construct(
        private PlayerRepository $playerRepository,
    ) {
    }

    /**
     * @throws PlayerNotFound
     */
    public function __invoke(UnsubscribeFromResultEmails $message): void
    {
        $this->playerRepository->get($message->playerId)->changeResultEmailsEnabled(false);
    }
}
