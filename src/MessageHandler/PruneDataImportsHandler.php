<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\MessageHandler;

use DateTimeImmutable;
use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;
use SpeedPuzzling\Web\Entity\DataImport;
use SpeedPuzzling\Web\Message\FinishSettledImport;
use SpeedPuzzling\Web\Message\PruneDataImports;
use SpeedPuzzling\Web\Message\RecordDataImportsPruned;
use SpeedPuzzling\Web\Repository\DataImportRepository;
use SpeedPuzzling\Web\Services\DataImport\ImportFileStorage;
use SpeedPuzzling\Web\Services\DataImport\ImportSlots;
use SpeedPuzzling\Web\Value\DataImportLimits;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\HandledStamp;

/**
 * The imports' daily clean-up (docs/features/data-import/upload.md "Prune"):
 * - an upload the browser never confirmed within DataImportLimits::UNCONFIRMED_UPLOAD_HOURS: whatever arrived is
 *   deleted and the import with it (it holds nothing);
 * - a file older than DataImportLimits::KEEP_FILE_DAYS: the object is deleted, the import stays, marked "file removed";
 * - an import discarded more than DataImportLimits::SIGNATURE_GRACE_HOURS ago: its object once more (the discard
 *   deleted it, but an upload URL signed before may have put it back), then the import whole;
 * - an import that took rows and has nothing left to add is **finished** (upload.md "Finished"): each is looked at
 *   again and marked by FinishSettledImport, in its own transaction. Nothing of it is deleted.
 *
 * **A row is removed only when no signed upload URL for its key can still be valid** - an upload is signed for the
 * last time an hour before it is due here (ImportUpload::isRetry()), a discarded import waits an hour - and only
 * after its object was deleted: no object ever stays behind without a row that knows its key.
 *
 * Runs outside a transaction (PruneDataImports): this only reads and calls object storage; the rows are written by
 * RecordDataImportsPruned, a batch at a time. An object that will not be deleted keeps its row for the next run.
 */
#[AsMessageHandler]
readonly final class PruneDataImportsHandler
{
    // Per kind and run - far above a day's imports; a backlog is worked off over the following runs
    private const int PER_RUN = 500;
    private const int BATCH = 50;

    public function __construct(
        private DataImportRepository $dataImportRepository,
        private ImportFileStorage $storage,
        private ImportSlots $importSlots,
        private MessageBusInterface $messageBus,
        private ClockInterface $clock,
        private LoggerInterface $logger,
    ) {
    }

    /**
     * @return array{unconfirmed: int, files: int, discarded: int, finished: int}
     */
    public function __invoke(PruneDataImports $message): array
    {
        $now = $this->clock->now();

        $unconfirmed = $this->dataImportRepository->uploadsStartedBefore(
            $now->modify('-' . DataImportLimits::UNCONFIRMED_UPLOAD_HOURS . ' hours'),
            self::PER_RUN,
        );
        $files = $this->dataImportRepository->withFileCreatedBefore(
            $now->modify('-' . DataImportLimits::KEEP_FILE_DAYS . ' days'),
            self::PER_RUN,
        );
        $discarded = $this->dataImportRepository->discardedBefore(
            $now->modify('-' . DataImportLimits::SIGNATURE_GRACE_HOURS . ' hours'),
            self::PER_RUN,
        );

        return [
            'unconfirmed' => $this->prune($unconfirmed, remove: true),
            'files' => $this->prune($files, remove: false),
            'discarded' => $this->prune($discarded, remove: true),
            'finished' => $this->finishSettled($now),
        ];
    }

    /**
     * Imports nothing is left to add from. One that can not be looked at keeps its state for the next run.
     */
    private function finishSettled(DateTimeImmutable $now): int
    {
        $finished = 0;

        foreach ($this->importSlots->toLookAt($now, self::PER_RUN) as $import) {
            try {
                // Not routed: handled at once, in its own transaction - the rule is asked again there, under the locks
                $envelope = $this->messageBus->dispatch(new FinishSettledImport($import['importId'], $import['playerId']));
            } catch (\Throwable $e) {
                $this->logger->warning('Could not look whether an import is finished - the next daily prune tries again', [
                    'import_id' => $import['importId'],
                    'exception' => $e,
                ]);

                continue;
            }

            if ($envelope->last(HandledStamp::class)?->getResult() === true) {
                $finished++;
            }
        }

        return $finished;
    }

    /**
     * @param list<DataImport> $imports
     */
    private function prune(array $imports, bool $remove): int
    {
        $done = 0;

        foreach (array_chunk($imports, self::BATCH) as $batch) {
            $importIds = [];

            foreach ($batch as $import) {
                if ($this->deleteFile($import)) {
                    $importIds[] = $import->id->toString();
                }
            }

            if ($importIds === []) {
                continue;
            }

            $this->messageBus->dispatch($remove
                ? new RecordDataImportsPruned(removeImportIds: $importIds, fileRemovedImportIds: [])
                : new RecordDataImportsPruned(removeImportIds: [], fileRemovedImportIds: $importIds));

            $done += count($importIds);
        }

        return $done;
    }

    private function deleteFile(DataImport $import): bool
    {
        if ($import->fileKey === null) {
            return true;
        }

        try {
            $this->storage->delete($import->fileKey);

            return true;
        } catch (\Throwable $e) {
            $this->logger->warning('Could not delete an import\'s file - the next daily prune tries again', [
                'import_id' => $import->id->toString(),
                'exception' => $e,
            ]);

            return false;
        }
    }
}
