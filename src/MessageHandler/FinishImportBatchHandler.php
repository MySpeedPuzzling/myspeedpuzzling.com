<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\MessageHandler;

use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Entity\Notification;
use SpeedPuzzling\Web\Message\FinishImportBatch;
use SpeedPuzzling\Web\Message\SendImportAddedEmail;
use SpeedPuzzling\Web\Repository\DataImportBatchRepository;
use SpeedPuzzling\Web\Repository\DataImportRowRepository;
use SpeedPuzzling\Web\Repository\NotificationRepository;
use SpeedPuzzling\Web\Services\DataImport\ImportedResultsFollowUp;
use SpeedPuzzling\Web\Services\Doctrine\IdLock;
use SpeedPuzzling\Web\Value\NotificationType;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * The end of an "Add" (docs/features/data-import/adding.md "The end of a batch"), in a small transaction under the
 * batch's lock - so two deliveries finish it once. Its rows are all through and what is owed per result is done
 * (FollowUpImportBatch, in chunks); what is left is once per batch:
 *
 * - once per person: the importing player's achievements, and one prediction backfill when a solo result waits for
 *   one (ImportedResultsFollowUp::forPeople() - an import's results have one registered person, the importer);
 * - the batch finished, with what its rows say now;
 * - the player told once: ONE notification and - when `player.import_emails` is on - ONE e-mail. Nobody else is told
 *   anything: no follower, no feed (the writer took PuzzleSolved off every result). **A batch an Undo stopped tells
 *   nothing** - "N added" just before they are taken back would be noise; the Undo speaks for itself.
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

        if ($batch === null || $batch->isRunning() === false || $batch->followedUpAt === null) {
            return;
        }

        $counts = $this->rowRepository->countsOfBatch($message->batchId);

        if ($counts['queued'] > 0) {
            return;
        }

        $now = $this->clock->now();
        $player = $batch->import->player;
        $playerId = $player->id->toString();

        if ($batch->followedUpTo !== null) {
            $this->followUp->forPeople([$playerId], $batch->predictionsOwed ? [$playerId] : []);
        }

        $batch->finished($counts, $now);
        $batch->import->workedOn($now);

        if ($batch->endedByUndo === false) {
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
}
