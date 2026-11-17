<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\MessageHandler;

use SpeedPuzzling\Web\Message\CreatePastEvent;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * Every message runs nested in this handler's transaction (each in its own savepoint): a refused event rolls back the
 * organization and series created for it.
 */
#[AsMessageHandler]
readonly final class CreatePastEventHandler
{
    public function __construct(
        private MessageBusInterface $messageBus,
    ) {
    }

    public function __invoke(CreatePastEvent $message): void
    {
        if ($message->organization !== null) {
            $this->messageBus->dispatch($message->organization);
        }

        if ($message->series !== null) {
            $this->messageBus->dispatch($message->series);
        }

        if ($message->competition !== null) {
            $this->messageBus->dispatch($message->competition);
        }

        if ($message->edition !== null) {
            $this->messageBus->dispatch($message->edition);
        }
    }
}
