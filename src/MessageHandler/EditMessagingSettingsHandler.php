<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\MessageHandler;

use SpeedPuzzling\Web\Exceptions\PlayerNotFound;
use SpeedPuzzling\Web\Message\EditMessagingSettings;
use SpeedPuzzling\Web\Repository\PlayerRepository;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
readonly final class EditMessagingSettingsHandler
{
    public function __construct(
        private PlayerRepository $playerRepository,
    ) {
    }

    /**
     * @throws PlayerNotFound
     */
    public function __invoke(EditMessagingSettings $message): void
    {
        $player = $this->playerRepository->get($message->playerId);

        $player->changeAllowDirectMessages($message->allowDirectMessages);
        $player->changeEmailNotificationsEnabled($message->emailNotificationsEnabled);
        $player->changeEmailNotificationFrequency($message->emailNotificationFrequency);
        $player->changeNewsletterEnabled($message->newsletterEnabled);
    }
}
