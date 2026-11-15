<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\MessageHandler;

use SpeedPuzzling\Web\Exceptions\PlayerNotFound;
use SpeedPuzzling\Web\Message\ChangeImportEmails;
use SpeedPuzzling\Web\Repository\PlayerRepository;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * Only the e-mails about a finished "Add" - the notification in the app and the other e-mails stay as they are.
 */
#[AsMessageHandler]
readonly final class ChangeImportEmailsHandler
{
    public function __construct(
        private PlayerRepository $playerRepository,
    ) {
    }

    /**
     * @throws PlayerNotFound
     */
    public function __invoke(ChangeImportEmails $message): void
    {
        $this->playerRepository->get($message->playerId)->changeImportEmails($message->enabled);
    }
}
