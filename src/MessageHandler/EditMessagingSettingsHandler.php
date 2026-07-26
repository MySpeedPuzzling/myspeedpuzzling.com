<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\MessageHandler;

use SpeedPuzzling\Web\Exceptions\PlayerNotFound;
use SpeedPuzzling\Web\Message\EditMessagingSettings;
use SpeedPuzzling\Web\Message\PushNewsletterSubscriberToListmonk;
use SpeedPuzzling\Web\Repository\PlayerRepository;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Messenger\MessageBusInterface;

#[AsMessageHandler]
readonly final class EditMessagingSettingsHandler
{
    public function __construct(
        private PlayerRepository $playerRepository,
        private MessageBusInterface $messageBus,
    ) {
    }

    /**
     * @throws PlayerNotFound
     */
    public function __invoke(EditMessagingSettings $message): void
    {
        $player = $this->playerRepository->get($message->playerId);

        $newsletterChanged = $player->newsletterEnabled !== $message->newsletterEnabled;

        $player->changeAllowDirectMessages($message->allowDirectMessages);
        $player->changeEmailNotificationsEnabled($message->emailNotificationsEnabled);
        $player->changeEmailNotificationFrequency($message->emailNotificationFrequency);
        $player->changeNewsletterEnabled($message->newsletterEnabled);

        if ($newsletterChanged && $player->email !== null) {
            $this->messageBus->dispatch(new PushNewsletterSubscriberToListmonk($player->email));
        }
    }
}
