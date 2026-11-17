<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\MessageHandler;

use SpeedPuzzling\Web\Message\SetUpEventWizardStructure;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * Every message runs nested in this handler's transaction (each in its own savepoint, flushed before the next one
 * reads), like SetUpEventWizardRoundHandler: whatever refuses rolls back everything the step wrote.
 */
#[AsMessageHandler]
readonly final class SetUpEventWizardStructureHandler
{
    public function __construct(
        private MessageBusInterface $messageBus,
    ) {
    }

    public function __invoke(SetUpEventWizardStructure $message): void
    {
        foreach ([...$message->standingsToDelete, ...$message->deletes, ...$message->edits, ...$message->adds, ...$message->advancementSteps, ...$message->standings] as $inner) {
            $this->messageBus->dispatch($inner);
        }
    }
}
