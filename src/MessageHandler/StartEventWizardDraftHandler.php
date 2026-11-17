<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\MessageHandler;

use SpeedPuzzling\Web\Message\StartEventWizardDraft;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * Both messages run nested in this handler's transaction (each in its own savepoint), like
 * AddCompetitionRoundWithPuzzlesHandler: whatever refuses the round rolls the event back too.
 */
#[AsMessageHandler]
readonly final class StartEventWizardDraftHandler
{
    public function __construct(
        private MessageBusInterface $messageBus,
    ) {
    }

    public function __invoke(StartEventWizardDraft $message): void
    {
        $this->messageBus->dispatch($message->competition);
        $this->messageBus->dispatch($message->round);
    }
}
