<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\MessageHandler;

use SpeedPuzzling\Web\Exceptions\PlayerNotFound;
use SpeedPuzzling\Web\Message\EditMessagingSettings;
use SpeedPuzzling\Web\Message\PushNewsletterSubscriberToListmonk;
use SpeedPuzzling\Web\Repository\PlayerRepository;
use SpeedPuzzling\Web\Services\PlayerAccountEmail;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Messenger\MessageBusInterface;

#[AsMessageHandler]
readonly final class EditMessagingSettingsHandler
{
    public function __construct(
        private PlayerRepository $playerRepository,
        private MessageBusInterface $messageBus,
        private PlayerAccountEmail $playerAccountEmail,
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

        $playerEmail = $newsletterChanged ? $this->playerAccountEmail->ofPlayer($player) : null;

        if ($playerEmail !== null) {
            $this->messageBus->dispatch(new PushNewsletterSubscriberToListmonk($playerEmail));
        }
    }
}
