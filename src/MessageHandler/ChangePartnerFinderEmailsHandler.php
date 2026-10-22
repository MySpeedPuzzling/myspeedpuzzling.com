<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\MessageHandler;

use SpeedPuzzling\Web\Exceptions\PlayerNotFound;
use SpeedPuzzling\Web\Message\ChangePartnerFinderEmails;
use SpeedPuzzling\Web\Repository\PlayerRepository;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * Only the partner finder e-mails - the notifications in the app, the digest, the newsletter and the other e-mails stay
 * as they are.
 */
#[AsMessageHandler]
readonly final class ChangePartnerFinderEmailsHandler
{
    public function __construct(
        private PlayerRepository $playerRepository,
    ) {
    }

    /**
     * @throws PlayerNotFound
     */
    public function __invoke(ChangePartnerFinderEmails $message): void
    {
        $this->playerRepository->get($message->playerId)->changePartnerFinderEmails($message->enabled);
    }
}
