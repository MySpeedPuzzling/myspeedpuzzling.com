<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\MessageHandler;

use SpeedPuzzling\Web\Exceptions\PlayerNotFound;
use SpeedPuzzling\Web\Message\UnsubscribeFromFeatureRequestEmails;
use SpeedPuzzling\Web\Repository\PlayerRepository;
use SpeedPuzzling\Web\Value\FeatureRequestEmails;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * One-click unsubscribe from the feature request e-mails - sets them to None (also about the player's own requests),
 * every other e-mail stays as it is.
 */
#[AsMessageHandler]
readonly final class UnsubscribeFromFeatureRequestEmailsHandler
{
    public function __construct(
        private PlayerRepository $playerRepository,
    ) {
    }

    /**
     * @throws PlayerNotFound
     */
    public function __invoke(UnsubscribeFromFeatureRequestEmails $message): void
    {
        $this->playerRepository->get($message->playerId)->changeFeatureRequestEmails(FeatureRequestEmails::None);
    }
}
