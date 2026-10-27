<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\MessageHandler;

use SpeedPuzzling\Web\Exceptions\PlayerNotFound;
use SpeedPuzzling\Web\Message\ChangeListingRequestEmails;
use SpeedPuzzling\Web\Repository\PlayerRepository;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * Only the sell/swap request e-mails - the notifications in the app and the other e-mails stay as they are.
 */
#[AsMessageHandler]
readonly final class ChangeListingRequestEmailsHandler
{
    public function __construct(
        private PlayerRepository $playerRepository,
    ) {
    }

    /**
     * @throws PlayerNotFound
     */
    public function __invoke(ChangeListingRequestEmails $message): void
    {
        $this->playerRepository->get($message->playerId)->changeListingRequestEmails($message->enabled);
    }
}
