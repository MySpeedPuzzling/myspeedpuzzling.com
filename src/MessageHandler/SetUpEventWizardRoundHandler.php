<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\MessageHandler;

use Doctrine\ORM\EntityManagerInterface;
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
        private EntityManagerInterface $entityManager,
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

        if ($message->arrange !== null || $message->unboxed !== null) {
            // Every row added above is flushed - the round's puzzle collection the identity map may still hold from before
            // the adds would miss them (ArrangeRoundPuzzles / ChangeRoundUnboxed read it)
            $this->entityManager->clear();
        }

        if ($message->arrange !== null) {
            $this->messageBus->dispatch($message->arrange);
        }

        if ($message->unboxed !== null) {
            $this->messageBus->dispatch($message->unboxed);
        }

        if ($message->format !== null) {
            $this->messageBus->dispatch($message->format);
        }
    }
}
