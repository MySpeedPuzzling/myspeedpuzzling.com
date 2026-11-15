<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\MessageHandler;

use SpeedPuzzling\Web\Message\AnswerImportPuzzlePile;
use SpeedPuzzling\Web\Services\DataImport\ImportPuzzlePile;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * One turn of an answer for a whole pile, outside a transaction (ImportPuzzlePile::turn() - each step a message
 * with a transaction of its own): a chunk, then the job sends itself again - other players' jobs take their turns
 * in between. A turn that fails is tried again by the transport; an answer that gives no sign of life for a quarter
 * of an hour is ended by the sweeper (ContinueImportBatchesHandler), so its pile can be answered again.
 */
#[AsMessageHandler]
readonly final class AnswerImportPuzzlePileHandler
{
    public function __construct(
        private ImportPuzzlePile $pile,
        private MessageBusInterface $messageBus,
    ) {
    }

    public function __invoke(AnswerImportPuzzlePile $message): void
    {
        if ($this->pile->turn($message->answerId)) {
            $this->messageBus->dispatch(new AnswerImportPuzzlePile($message->answerId));
        }
    }
}
