<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\MessageHandler;

use Doctrine\ORM\EntityManagerInterface;
use SpeedPuzzling\Web\Exceptions\DataImportNotFound;
use SpeedPuzzling\Web\Message\AnswerImportPuzzlePile;
use SpeedPuzzling\Web\Services\DataImport\ImportPuzzlePile;
use SpeedPuzzling\Web\Value\ImportPuzzleChoice;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * One turn of an answer for a whole pile, outside a transaction (the chunk's decisions are a message with a
 * transaction of its own): a chunk, then the job sends itself again - other players' jobs take their turns in
 * between - or says the answer has ended. An import that is gone, discarded or finished meanwhile ends it quietly.
 * A turn that fails is tried again by the transport; the entry that says "an answer runs" frees itself.
 */
#[AsMessageHandler]
readonly final class AnswerImportPuzzlePileHandler
{
    public function __construct(
        private ImportPuzzlePile $pile,
        private MessageBusInterface $messageBus,
        private EntityManagerInterface $entityManager,
    ) {
    }

    public function __invoke(AnswerImportPuzzlePile $message): void
    {
        $this->entityManager->clear();
        $choice = ImportPuzzleChoice::tryFrom($message->choice);

        if ($choice === null) {
            $this->pile->ended($message->importId);

            return;
        }

        try {
            $turn = $this->pile->turn($message->importId, $message->playerId, $message->pile, $choice, $message->after);
        } catch (DataImportNotFound) {
            $this->pile->ended($message->importId);

            return;
        }

        if ($turn['after'] === null) {
            $this->pile->ended($message->importId);

            return;
        }

        $this->pile->markRunning($message->importId, $message->pile, $choice);
        $this->messageBus->dispatch(new AnswerImportPuzzlePile($message->importId, $message->playerId, $message->pile, $message->choice, $turn['after']));
    }
}
