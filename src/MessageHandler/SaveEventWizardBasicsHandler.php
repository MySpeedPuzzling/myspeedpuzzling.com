<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\MessageHandler;

use SpeedPuzzling\Web\Message\SaveEventWizardBasics;
use SpeedPuzzling\Web\Query\GetEventWizardState;
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
        private GetEventWizardState $getEventWizardState,
    ) {
    }

    public function __invoke(SaveEventWizardBasics $message): void
    {
        $this->messageBus->dispatch($message->competition);

        if ($message->roundEdit !== null) {
            $this->messageBus->dispatch($message->roundEdit);
        }

        // The page decided "no round yet" before this message took the event's lock - a second save of the same page
        // (a double click, another tab) found none either. Read again under the lock: the round exists once.
        if ($message->newRound !== null && $this->getEventWizardState->of($message->competitionId)->roundsCount === 0) {
            $this->messageBus->dispatch($message->newRound);
        }
    }
}
