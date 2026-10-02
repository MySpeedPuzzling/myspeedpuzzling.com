<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\MessageHandler;

use SpeedPuzzling\Web\Exceptions\PlayerNotFound;
use SpeedPuzzling\Web\Message\PushNewsletterSubscriberToListmonk;
use SpeedPuzzling\Web\Message\TurnOnNewsletter;
use SpeedPuzzling\Web\Repository\PlayerRepository;
use SpeedPuzzling\Web\Services\PlayerAccountEmail;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Messenger\MessageBusInterface;

#[AsMessageHandler]
readonly final class TurnOnNewsletterHandler
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
    public function __invoke(TurnOnNewsletter $message): void
    {
        $player = $this->playerRepository->get($message->playerId);

        if ($player->newsletterEnabled) {
            return;
        }

        $player->changeNewsletterEnabled(true);

        // An explicit user action, so the push may re-confirm a Listmonk unsubscribe (docs/features/newsletter/README.md)
        $playerEmail = $this->playerAccountEmail->ofPlayer($player);

        if ($playerEmail !== null) {
            $this->messageBus->dispatch(new PushNewsletterSubscriberToListmonk($playerEmail));
        }
    }
}
