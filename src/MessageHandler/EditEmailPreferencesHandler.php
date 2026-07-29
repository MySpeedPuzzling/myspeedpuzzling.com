<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\MessageHandler;

use SpeedPuzzling\Web\Exceptions\PlayerNotFound;
use SpeedPuzzling\Web\Message\EditEmailPreferences;
use SpeedPuzzling\Web\Message\PushNewsletterSubscriberToListmonk;
use SpeedPuzzling\Web\Repository\PlayerRepository;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * Saves the e-mail related subset of player settings from the standalone
 * (token-authenticated) e-mail preferences page. Deliberately narrower than
 * EditMessagingSettings - the page does not show allowDirectMessages, so the
 * message cannot touch it.
 */
#[AsMessageHandler]
readonly final class EditEmailPreferencesHandler
{
    public function __construct(
        private PlayerRepository $playerRepository,
        private MessageBusInterface $messageBus,
    ) {
    }

    /**
     * @throws PlayerNotFound
     */
    public function __invoke(EditEmailPreferences $message): void
    {
        $player = $this->playerRepository->get($message->playerId);

        $newsletterChanged = $player->newsletterEnabled !== $message->newsletterEnabled;

        $player->changeEmailNotificationsEnabled($message->emailNotificationsEnabled);
        $player->changeEmailNotificationFrequency($message->emailNotificationFrequency);
        $player->changeNewsletterEnabled($message->newsletterEnabled);

        if ($newsletterChanged && $player->email !== null) {
            $this->messageBus->dispatch(new PushNewsletterSubscriberToListmonk($player->email));
        }
    }
}
