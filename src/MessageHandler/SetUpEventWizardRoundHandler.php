<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\MessageHandler;

use SpeedPuzzling\Web\Message\SetUpEventWizardRound;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * Every message runs nested in this handler's transaction (each in its own savepoint, flushed before the next one
 * reads), like AddCompetitionRoundWithPuzzlesHandler: whatever refuses rolls back everything the step wrote.
 */
#[AsMessageHandler]
readonly final class SetUpEventWizardRoundHandler
{
    public function __construct(
        private MessageBusInterface $messageBus,
    ) {
    }

    public function __invoke(SetUpEventWizardRound $message): void
    {
        $this->messageBus->dispatch($message->round);

        foreach ($message->puzzles as $puzzle) {
            $this->messageBus->dispatch($puzzle);
        }

        foreach ($message->reveals as $reveal) {
            $this->messageBus->dispatch($reveal);
        }

        if ($message->format !== null) {
            $this->messageBus->dispatch($message->format);
        }
    }
}
