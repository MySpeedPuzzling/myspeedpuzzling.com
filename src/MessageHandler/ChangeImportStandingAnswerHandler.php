<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\MessageHandler;

use Psr\Clock\ClockInterface;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Entity\DataImport;
use SpeedPuzzling\Web\Exceptions\DataImportNotFound;
use SpeedPuzzling\Web\Message\ChangeImportStandingAnswer;
use SpeedPuzzling\Web\Repository\DataImportRepository;
use SpeedPuzzling\Web\Services\Doctrine\IdLock;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * An answer that stood for the whole file is taken back (ChangeImportStandingAnswer), under the import's lock - the
 * lock every answer about its rows takes. Somebody else's import, an import rows can not be added from and a
 * question no answer can stand for are "not found": a page never sends them.
 */
#[AsMessageHandler]
readonly final class ChangeImportStandingAnswerHandler
{
    public function __construct(
        private DataImportRepository $dataImportRepository,
        private IdLock $idLock,
        private ClockInterface $clock,
    ) {
    }

    /**
     * @throws DataImportNotFound
     */
    public function __invoke(ChangeImportStandingAnswer $message): bool
    {
        if (Uuid::isValid($message->importId) === false || in_array($message->code, DataImport::STANDING_QUESTIONS, true) === false) {
            throw new DataImportNotFound();
        }

        $this->idLock->lockUntilCommit(Uuid::fromString($message->importId));
        $import = $this->dataImportRepository->getOwnedBy($message->importId, $message->playerId);

        if ($import->isBeingSetUp() === false || $import->cellsWipedAt !== null) {
            throw new DataImportNotFound();
        }

        $changed = $import->standingAnswerTakenBack($message->code);

        if ($changed) {
            $import->workedOn($this->clock->now());
        }

        return $changed;
    }
}
