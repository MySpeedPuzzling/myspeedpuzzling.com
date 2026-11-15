<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\MessageHandler;

use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Entity\Notification;
use SpeedPuzzling\Web\Entity\PuzzleSolvingTime;
use SpeedPuzzling\Web\Message\FinishImportBatch;
use SpeedPuzzling\Web\Message\SendImportAddedEmail;
use SpeedPuzzling\Web\Repository\DataImportBatchRepository;
use SpeedPuzzling\Web\Repository\DataImportRowRepository;
use SpeedPuzzling\Web\Repository\NotificationRepository;
use SpeedPuzzling\Web\Services\DataImport\ImportedResultsFollowUp;
use SpeedPuzzling\Web\Services\Doctrine\IdLock;
use SpeedPuzzling\Web\Value\DataImport\ImportedResultOutcome;
use SpeedPuzzling\Web\Value\NotificationType;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * The end of an "Add" (docs/features/data-import/adding.md "The end of a batch"), in a transaction after the rows are
 * committed, under the batch's lock - so two deliveries finish it once:
 *
 * - what is owed for the results, **once for the batch** (ImportedResultsFollowUp): the statistics of every puzzle
 *   touched, XP per result, achievements per person, one prediction backfill per player;
 * - the batch finished, with what its rows say now;
 * - the player told once: ONE notification and - when `player.import_emails` is on - ONE e-mail. Nobody else is told
 *   anything: no follower, no feed (the writer took PuzzleSolved off every result).
 *
 * A batch with more than a tenth of its rows failed on our side is a warning: a person should look.
 */
#[AsMessageHandler]
readonly final class FinishImportBatchHandler
{
    public function __construct(
        private DataImportBatchRepository $batchRepository,
        private DataImportRowRepository $rowRepository,
        private NotificationRepository $notificationRepository,
        private ImportedResultsFollowUp $followUp,
        private EntityManagerInterface $entityManager,
        private MessageBusInterface $messageBus,
        private IdLock $idLock,
        private ClockInterface $clock,
        private LoggerInterface $logger,
    ) {
    }

    public function __invoke(FinishImportBatch $message): void
    {
        if (Uuid::isValid($message->batchId) === false) {
            return;
        }

        $this->idLock->lockUntilCommit(Uuid::fromString($message->batchId));
        $batch = $this->batchRepository->find($message->batchId);

        if ($batch === null || $batch->isRunning() === false) {
            return;
        }

        $counts = $this->rowRepository->countsOfBatch($message->batchId);

        if ($counts['queued'] > 0) {
            return;
        }

        $this->followUp->afterResultsAdded($this->outcomesOf($message->batchId));

        $now = $this->clock->now();
        $batch->finished($counts, $now);
        $batch->import->workedOn($now);

        $player = $batch->import->player;
        $this->notificationRepository->save(new Notification(
            Uuid::uuid7(),
            $player,
            NotificationType::ImportRowsAdded,
            $now,
            targetDataImportBatch: $batch,
        ));
        $batch->playerNotified($now);

        if ($player->importEmails) {
            $this->messageBus->dispatch(new SendImportAddedEmail($message->batchId));
        }

        if ($counts['failed'] * 10 > $batch->rowsRequested) {
            $this->logger->warning('More than a tenth of the rows of an import batch failed on our side', [
                'batch_id' => $message->batchId,
                'import_id' => $batch->import->id->toString(),
                'rows_requested' => $batch->rowsRequested,
                'rows_failed' => $counts['failed'],
            ]);
        }
    }

    /**
     * What the follow-up needs of every result the batch made, read from the results as they are now - a result
     * deleted meanwhile owes nothing. One result in memory at a time.
     *
     * @return list<ImportedResultOutcome>
     */
    private function outcomesOf(string $batchId): array
    {
        $outcomes = [];

        foreach ($this->rowRepository->solvingTimeIdsMadeByBatch($batchId) as $rowId => $solvingTimeId) {
            $solvingTime = $this->entityManager->find(PuzzleSolvingTime::class, $solvingTimeId);

            if ($solvingTime === null) {
                continue;
            }

            $outcomes[] = ImportedResultOutcome::added(
                importRowId: $rowId,
                solvingTimeId: $solvingTimeId,
                puzzleId: $solvingTime->puzzle->id->toString(),
                memberPlayerIds: $solvingTime->memberPlayerIds(),
                predictionPending: $solvingTime->isPredictionPending(),
                removedWishListItem: null,
            );

            $this->entityManager->detach($solvingTime);
        }

        return $outcomes;
    }
}
