<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\MessageHandler;

use Psr\Log\LoggerInterface;
use SpeedPuzzling\Web\Exceptions\GoogleSheetNotFetched;
use SpeedPuzzling\Web\Message\BeginImportFetch;
use SpeedPuzzling\Web\Message\RecordImportFetched;
use SpeedPuzzling\Web\Message\RecordImportFetchFailed;
use SpeedPuzzling\Web\Message\RequestImportRead;
use SpeedPuzzling\Web\Message\StartImportFromGoogleSheet;
use SpeedPuzzling\Web\Services\DataImport\GoogleSheetFile;
use SpeedPuzzling\Web\Services\DataImport\ImportFileStorage;
use SpeedPuzzling\Web\Services\DataImport\ImportProgress;
use SpeedPuzzling\Web\Value\DataImportFileKind;
use SpeedPuzzling\Web\Value\DataImportLimits;
use SpeedPuzzling\Web\Value\DataImportReadFailure;
use SpeedPuzzling\Web\Value\ImportFetchJob;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\HandledStamp;

/**
 * Fetches an import's Google sheet and hands it to the reading (docs/features/data-import/google-sheets.md).
 *
 * Outside a transaction (StartImportFromGoogleSheet): Google is waited for up to a minute.
 *
 * 1. BeginImportFetch: the import is taken, committed before Google is asked.
 * 2. GoogleSheetFile streams the export into a local temporary file - checked hop by hop, capped, bounded in time.
 * 3. The file is stored under the import's key like an uploaded one (ImportFileStorage), then RecordImportFetched:
 *    `fetching` → `uploaded`. Refused (discarded meanwhile, given up, started again) - the stored file is removed.
 * 4. RequestImportRead queues the reading (stage 3b) - from here on nothing differs from an upload.
 *
 * What Google answered (GoogleSheetNotFetched) is the player's news, logged at info - at warning when Google sent us
 * somewhere an export never goes. Anything else is ours: logged as an error, shown as "something went wrong on our
 * side". Nothing is thrown on: the queue has no retries, a failed fetch is recorded on the import and started again
 * by the player.
 */
#[AsMessageHandler]
readonly final class StartImportFromGoogleSheetHandler
{
    public function __construct(
        private MessageBusInterface $messageBus,
        private GoogleSheetFile $googleSheetFile,
        private ImportFileStorage $storage,
        private ImportProgress $progress,
        private LoggerInterface $logger,
        /** The import's limit (README Q8) - an argument only so that a test can fetch a small file over it */
        private int $maxBytes = DataImportLimits::MAX_FILE_BYTES,
    ) {
    }

    public function __invoke(StartImportFromGoogleSheet $message): void
    {
        $job = $this->ask(new BeginImportFetch($message->importId));

        if ($job instanceof ImportFetchJob === false) {
            return;
        }

        $path = null;

        try {
            $path = $this->temporaryPath();
            $fetched = $this->googleSheetFile->fetchTo($job->spreadsheetId, $path, $this->maxBytes);

            $this->storage->store($job->fileKey, $path, DataImportFileKind::Xlsx);

            if ($this->ask(new RecordImportFetched($job->importId, $job->fetchAttempt, $fetched->fileName, $fetched->bytes)) !== true) {
                // Not this fetch's import any more (discarded, given up, started again): nothing of it stays behind.
                // A fetch started again stores under the same key - after this one, or its own check of the size
                // (ReadImportFileHandler) fails and the player tries again
                $this->storage->delete($job->fileKey);

                return;
            }

            $this->ask(new RequestImportRead($job->importId));
        } catch (GoogleSheetNotFetched $e) {
            $this->logger->log($e->unexpected ? 'warning' : 'info', 'An import\'s Google sheet was not fetched: ' . $e->failure->value, [
                'import_id' => $job->importId,
                'reason' => $e->failure->value,
                'exception' => $e,
            ]);

            $this->failed($job, $e->failure->value);
        } catch (\Throwable $e) {
            $this->logger->error('Fetching an import\'s Google sheet failed on our side', [
                'import_id' => $job->importId,
                'exception' => $e,
            ]);

            $this->failed($job, DataImportReadFailure::OUR_SIDE);
        } finally {
            if ($path !== null && is_file($path)) {
                unlink($path);
            }

            $this->progress->publish($job->importId);
        }
    }

    private function failed(ImportFetchJob $job, string $reason): void
    {
        try {
            $this->messageBus->dispatch(new RecordImportFetchFailed($job->importId, $reason, $job->fetchAttempt));
        } catch (\Throwable $e) {
            // The import stays "being fetched" - myspeedpuzzling:read-waiting-imports ends that
            $this->logger->error('A failed fetch of an import\'s Google sheet could not be recorded', [
                'import_id' => $job->importId,
                'exception' => $e,
            ]);
        }
    }

    private function ask(object $message): mixed
    {
        return $this->messageBus->dispatch($message)->last(HandledStamp::class)?->getResult();
    }

    private function temporaryPath(): string
    {
        // The folder of the files being read: what a killed worker leaves there is pruned by the next read
        $folder = rtrim(sys_get_temp_dir(), '/') . '/' . ReadImportFileHandler::TEMPORARY_FOLDER;

        if (is_dir($folder) === false && mkdir($folder, 0700, true) === false && is_dir($folder) === false) {
            throw new \RuntimeException('The folder for the files being fetched can not be made.');
        }

        return $folder . '/' . bin2hex(random_bytes(16));
    }
}
