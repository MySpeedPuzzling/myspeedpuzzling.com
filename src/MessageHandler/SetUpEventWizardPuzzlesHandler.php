<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\MessageHandler;

use SpeedPuzzling\Web\Message\SetUpEventWizardPuzzles;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * Every row's SetUpEventWizardRound runs nested in this handler's transaction: whatever refuses rolls back every row.
 */
#[AsMessageHandler]
readonly final class SetUpEventWizardPuzzlesHandler
{
    public function __construct(
        private MessageBusInterface $messageBus,
    ) {
    }

    public function __invoke(SetUpEventWizardPuzzles $message): void
    {
        foreach ($message->rounds as $round) {
            $this->messageBus->dispatch($round);
        }
    }
}
