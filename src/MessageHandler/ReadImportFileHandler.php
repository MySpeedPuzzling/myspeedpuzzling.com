<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\MessageHandler;

use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Exceptions\SpreadsheetUnreadable;
use SpeedPuzzling\Web\Message\BeginImportReading;
use SpeedPuzzling\Web\Message\ReadImportFile;
use SpeedPuzzling\Web\Message\RecordImportFileHash;
use SpeedPuzzling\Web\Message\RecordImportRead;
use SpeedPuzzling\Web\Message\RecordImportReadFailed;
use SpeedPuzzling\Web\Message\RecordImportRows;
use SpeedPuzzling\Web\Message\RecordImportSheets;
use SpeedPuzzling\Web\Services\DataImport\ImportFileStorage;
use SpeedPuzzling\Web\Services\DataImport\ImportProgress;
use SpeedPuzzling\Web\Services\DataImport\ImportRowCells;
use SpeedPuzzling\Web\Services\DataImport\ImportSheetHints;
use SpeedPuzzling\Web\Services\SpreadsheetReader\ReadWorkbook;
use SpeedPuzzling\Web\Services\SpreadsheetReader\SpreadsheetReader;
use SpeedPuzzling\Web\Value\DataImportFileKind;
use SpeedPuzzling\Web\Value\DataImportLimits;
use SpeedPuzzling\Web\Value\DataImportReadFailure;
use SpeedPuzzling\Web\Value\ImportReadingJob;
use SpeedPuzzling\Web\Value\Spreadsheet\SpreadsheetFormat;
use SpeedPuzzling\Web\Value\Spreadsheet\SpreadsheetLimits;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\HandledStamp;

/**
 * Reads an uploaded import's file into `data_import_sheet` and `data_import_row` (docs/features/data-import/reading.md).
 *
 * Outside a transaction (ReadImportFile): downloading and parsing take seconds to minutes. Every write is a small
 * message of its own, handled at once in its own short transaction, and each answers whether reading goes on - an
 * import that was discarded meanwhile, given up as stalled or started again stops this read at its next batch.
 *
 * 1. BeginImportReading: `uploaded` → `reading`, committed before the file is touched.
 * 2. The object is streamed to a local temporary file, its sha256 worked out on the way (RecordImportFileHash - "the
 *    same file again" is answered here).
 * 3. The file is opened once; its sheets are stored (RecordImportSheets), then **every sheet is read in one streaming
 *    pass** - a row is in memory only until its batch of at most DataImportLimits::ROWS_PER_BATCH is written
 *    (RecordImportRows). Memory does not grow with the file.
 * 4. RecordImportRead, or RecordImportReadFailed with the reason. After every commit the page is told (ImportProgress).
 *
 * What a file is or holds (SpreadsheetUnreadable) is the player's news, logged at info. Anything else is ours: logged
 * as an error, shown as "something went wrong on our side". Nothing is thrown on: the queue has no retries, a failed
 * read is recorded on the import and started again by the player.
 */
#[AsMessageHandler]
readonly final class ReadImportFileHandler
{
    /** Under the system's temporary folder: the files being read - a folder of their own, so leftovers can be pruned */
    public const string TEMPORARY_FOLDER = 'msp-import-files';

    public function __construct(
        private MessageBusInterface $messageBus,
        private ImportFileStorage $storage,
        private SpreadsheetReader $reader,
        private ImportProgress $progress,
        private ClockInterface $clock,
        private LoggerInterface $logger,
        /** The import's limits (README Q8) - arguments only so that a test can read a small file over them */
        private int $maxSheets = DataImportLimits::MAX_SHEETS,
        private int $maxRows = DataImportLimits::MAX_ROWS,
        private int $maxCells = DataImportLimits::MAX_CELLS,
    ) {
    }

    public function __invoke(ReadImportFile $message): void
    {
        $job = $this->ask(new BeginImportReading($message->importId));

        if ($job instanceof ImportReadingJob === false) {
            return;
        }

        $this->progress->publish($job->importId);

        $path = null;
        $workbook = null;
        $timeLimit = (int) ini_get('max_execution_time');
        // The reader bounds its own time; this is the stop from outside it (reader.md "What the job must still do"): a
        // parser that hangs dies with the worker, and the import is rescued by myspeedpuzzling:read-waiting-imports
        set_time_limit(DataImportLimits::READ_HARD_SECONDS);

        try {
            $this->pruneLeftovers();
            $path = $this->temporaryPath();
            $file = $this->storage->downloadTo($job->fileKey, $path);

            if ($file['bytes'] !== $job->fileSize) {
                throw new \RuntimeException(sprintf('The stored file has %d bytes, the upload was confirmed with %d.', $file['bytes'], $job->fileSize));
            }

            if ($this->ask(new RecordImportFileHash($job->importId, $job->readAttempt, $file['sha256'])) !== true) {
                return;
            }

            $workbook = $this->reader->open(
                $path,
                $job->fileKind === DataImportFileKind::Xlsx ? SpreadsheetFormat::Xlsx : SpreadsheetFormat::Csv,
                new SpreadsheetLimits(
                    maxFileBytes: DataImportLimits::MAX_FILE_BYTES,
                    maxSheets: $this->maxSheets,
                    maxRows: $this->maxRows,
                    maxRowsPerSheet: $this->maxRows,
                    maxCells: $this->maxCells,
                    maxReadSeconds: DataImportLimits::READ_CPU_SECONDS,
                    maxWallSeconds: DataImportLimits::READ_WALL_SECONDS,
                ),
            );

            if ($this->read($job, $workbook)) {
                $this->ask(new RecordImportRead($job->importId, $job->readAttempt));
            }
        } catch (SpreadsheetUnreadable $e) {
            $this->logger->info('An import\'s file was not read: ' . $e->reason->value, [
                'import_id' => $job->importId,
                'reason' => $e->reason->value,
                'exception' => $e,
            ]);

            $this->failed($job, $e->reason->value);
        } catch (\Throwable $e) {
            $this->logger->error('Reading an import\'s file failed on our side', [
                'import_id' => $job->importId,
                'exception' => $e,
            ]);

            $this->failed($job, DataImportReadFailure::OUR_SIDE);
        } finally {
            $workbook?->close();

            if ($path !== null && is_file($path)) {
                unlink($path);
            }

            set_time_limit($timeLimit);
            $this->progress->publish($job->importId);
        }
    }

    /**
     * @return bool false: the import is not being read by this read any more - nothing further is written
     *
     * @throws SpreadsheetUnreadable
     */
    private function read(ImportReadingJob $job, ReadWorkbook $workbook): bool
    {
        $sheets = $workbook->sheets();
        $sheetIds = [];
        $found = [];

        foreach ($sheets as $index => $sheet) {
            $sheetIds[$index] = Uuid::uuid7()->toString();
            $found[] = [
                'id' => $sheetIds[$index],
                'position' => $sheet->position,
                'title' => $sheet->title,
                'hidden' => $sheet->hidden,
                'hints' => ImportSheetHints::of($sheet),
            ];
        }

        if ($this->ask(new RecordImportSheets($job->importId, $job->readAttempt, $found)) !== true) {
            return false;
        }

        $this->progress->publish($job->importId);
        $stored = 0;

        foreach ($sheets as $index => $sheet) {
            $rows = [];
            $bytes = 0;
            $lastColumn = 0;

            foreach ($workbook->rows($sheet) as $row) {
                $weight = ImportRowCells::weight($row);
                $stored += $weight;

                if ($stored > DataImportLimits::MAX_STORED_BYTES) {
                    throw SpreadsheetUnreadable::tooLarge('the rows hold more text than an import keeps');
                }

                $rows[] = ['number' => $row->number, 'cells' => ImportRowCells::of($row)];
                $bytes += $weight;
                $lastColumn = max($lastColumn, $row->lastColumn());

                if (count($rows) < DataImportLimits::ROWS_PER_BATCH && $bytes < DataImportLimits::BYTES_PER_BATCH) {
                    continue;
                }

                if ($this->ask(new RecordImportRows($job->importId, $job->readAttempt, $sheetIds[$index], $rows, $lastColumn, false)) !== true) {
                    return false;
                }

                $this->progress->publish($job->importId, always: false);
                $rows = [];
                $bytes = 0;
                $lastColumn = 0;
            }

            // The sheet's last rows - or none: the sheet is read
            if ($this->ask(new RecordImportRows($job->importId, $job->readAttempt, $sheetIds[$index], $rows, $lastColumn, true)) !== true) {
                return false;
            }

            $this->progress->publish($job->importId, always: false);
        }

        return true;
    }

    private function failed(ImportReadingJob $job, string $reason): void
    {
        try {
            $this->messageBus->dispatch(new RecordImportReadFailed($job->importId, $reason, $job->readAttempt));
        } catch (\Throwable $e) {
            // The import stays "being read" - myspeedpuzzling:read-waiting-imports ends that
            $this->logger->error('A failed read of an import could not be recorded', [
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
        $folder = self::temporaryFolder();

        if (is_dir($folder) === false && mkdir($folder, 0700, true) === false && is_dir($folder) === false) {
            throw new \RuntimeException('The folder for the files being read can not be made.');
        }

        return $folder . '/' . bin2hex(random_bytes(16));
    }

    /**
     * What a killed worker left behind: its temporary file here, the reader's own folders.
     */
    private function pruneLeftovers(): void
    {
        $before = $this->clock->now()->modify('-1 day');
        $this->reader->pruneLeftovers($before);

        foreach (glob(self::temporaryFolder() . '/*') ?: [] as $file) {
            $changed = filemtime($file);

            if (is_file($file) && $changed !== false && $changed < $before->getTimestamp()) {
                unlink($file);
            }
        }
    }

    private static function temporaryFolder(): string
    {
        return rtrim(sys_get_temp_dir(), '/') . '/' . self::TEMPORARY_FOLDER;
    }
}
