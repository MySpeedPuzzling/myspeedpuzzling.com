<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\MessageHandler;

use SpeedPuzzling\Web\Exceptions\PlayerNotFound;
use SpeedPuzzling\Web\Message\ChangeBorrowRequestEmails;
use SpeedPuzzling\Web\Repository\PlayerRepository;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * Only the borrow request e-mails - the notifications in the app and the other e-mails stay as they are.
 */
#[AsMessageHandler]
readonly final class ChangeBorrowRequestEmailsHandler
{
    public function __construct(
        private PlayerRepository $playerRepository,
    ) {
    }

    /**
     * @throws PlayerNotFound
     */
    public function __invoke(ChangeBorrowRequestEmails $message): void
    {
        $this->playerRepository->get($message->playerId)->changeBorrowRequestEmails($message->enabled);
    }
}
