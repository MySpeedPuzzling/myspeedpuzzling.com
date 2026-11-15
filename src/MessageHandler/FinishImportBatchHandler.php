<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\MessageHandler;

use DateTimeImmutable;
use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Entity\DataImportBatch;
use SpeedPuzzling\Web\Entity\Notification;
use SpeedPuzzling\Web\Exceptions\PlayerNotFound;
use SpeedPuzzling\Web\Message\FinishImportBatch;
use SpeedPuzzling\Web\Message\SendImportAddedEmail;
use SpeedPuzzling\Web\Query\GetUserBlocks;
use SpeedPuzzling\Web\Repository\DataImportBatchRepository;
use SpeedPuzzling\Web\Repository\DataImportPersonRepository;
use SpeedPuzzling\Web\Repository\PlayerRepository;
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
 * - once per person: the achievements of the importing player and of every registered partner on the batch's
 *   results, and one prediction backfill when a solo result waits for one (ImportedResultsFollowUp::forPeople());
 * - every registered partner told once, in the app: ONE notification per batch whatever the number of results
 *   (docs/features/data-import/people.md "What the partner is told");
 * - the batch finished, with what its rows say now;
 * - the player told once: ONE notification and - when `player.import_emails` is on - ONE e-mail. No follower is told
 *   anything and nothing reaches a feed (the writer took PuzzleSolved off every result). **A batch an Undo stopped tells
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
        private DataImportPersonRepository $personRepository,
        private PlayerRepository $playerRepository,
        private GetUserBlocks $getUserBlocks,
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

        // The registered partners on the results the batch made (a code of the file that resolved, a name the
        // player mapped - docs/features/data-import/people.md): each is owed what the player is, once
        $partnerResults = $this->personRepository->partnerResultsOfBatch($message->batchId, $playerId);
        $batch->partnersCounted($partnerResults);

        if ($batch->followedUpTo !== null) {
            $this->followUp->forPeople([$playerId, ...array_map(strval(...), array_keys($partnerResults))], $batch->predictionsOwed ? [$playerId] : []);
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
            $this->tellPartners($batch, $partnerResults, $now);

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

    /**
     * ONE notification per registered partner, whatever the number of results: "<importer> added N results with you
     * from a spreadsheet" - in the app only, never an e-mail. Nothing is created about the importing player for
     * somebody with a block between the two (a block that came after the results were written).
     *
     * @param array<string, int> $partnerResults player id => results
     */
    private function tellPartners(DataImportBatch $batch, array $partnerResults, DateTimeImmutable $now): void
    {
        $partnerIds = array_map(strval(...), array_keys(array_filter($partnerResults, static fn (int $results): bool => $results > 0)));

        if ($partnerIds === []) {
            return;
        }

        $importer = $batch->import->player;
        $blocked = array_map(strtolower(...), $this->getUserBlocks->blockedEitherWay($importer->id->toString(), $partnerIds));

        foreach ($partnerIds as $partnerId) {
            if (in_array(strtolower($partnerId), $blocked, true)) {
                continue;
            }

            try {
                $partner = $this->playerRepository->get($partnerId);
            } catch (PlayerNotFound) {
                continue;
            }

            $this->notificationRepository->save(new Notification(
                Uuid::uuid7(),
                $partner,
                NotificationType::ImportResultsAddedWithYou,
                $now,
                actorPlayer: $importer,
                targetDataImportBatch: $batch,
            ));
        }
    }
}
