<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\MessageHandler;

use SpeedPuzzling\Web\Message\SaveEventWizardBasics;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * The messages run nested in this handler's transaction (each in its own savepoint): a round refusing its new start
 * (SecretPuzzlesWouldBeRevealed - it would reveal a secret puzzle earlier) rolls the event's details back too.
 */
#[AsMessageHandler]
readonly final class SaveEventWizardBasicsHandler
{
    public function __construct(
        private MessageBusInterface $messageBus,
    ) {
    }

    public function __invoke(SaveEventWizardBasics $message): void
    {
        $this->messageBus->dispatch($message->competition);

        if ($message->roundEdit !== null) {
            $this->messageBus->dispatch($message->roundEdit);
        }

        if ($message->newRound !== null) {
            $this->messageBus->dispatch($message->newRound);
        }
    }
}
