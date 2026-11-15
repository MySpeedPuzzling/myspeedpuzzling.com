<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\MessageHandler;

use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use League\Flysystem\Filesystem;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use Psr\Clock\ClockInterface;
use Psr\Log\NullLogger;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Message\ConfirmImportUploaded;
use SpeedPuzzling\Web\Message\ReadImportFile;
use SpeedPuzzling\Web\Message\StartImport;
use SpeedPuzzling\Web\MessageHandler\ReadImportFileHandler;
use SpeedPuzzling\Web\Services\DataImport\ImportFileStorage;
use SpeedPuzzling\Web\Services\DataImport\ImportProgress;
use SpeedPuzzling\Web\Services\SpreadsheetReader\CpuClock;
use SpeedPuzzling\Web\Services\SpreadsheetReader\SpreadsheetReader;
use SpeedPuzzling\Web\Tests\DataFixtures\PlayerFixture;
use SpeedPuzzling\Web\Tests\Services\SpreadsheetReader\RawXlsx;
use SpeedPuzzling\Web\Value\DataImportFileKind;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\TraceableMessageBus;

/**
 * The largest files an import takes, through the whole reading job - download, hash, one streaming pass, 100 batches
 * of 500 rows written - with the time and the memory it took (docs/features/data-import/reading.md "Measured").
 *
 * Opt-in, not in CI (a million cells and 50,000 inserted rows take about a minute):
 * DATA_IMPORT_LARGE=1 vendor/bin/phpunit --group data-import-large tests/MessageHandler/ReadImportFileLargeTest.php
 */
final class ReadImportFileLargeTest extends KernelTestCase
{
    private const int MEBIBYTE = 1_048_576;

    /**
     * @return iterable<string, array{int}> megabytes of pictures added to the workbook
     */
    public static function files(): iterable
    {
        yield '50,000 rows × 20 columns' => [0];
        yield 'the same with 22 MiB of pictures - about 24 MiB, the largest file allowed' => [22];
    }

    #[Group('data-import-large')]
    #[DataProvider('files')]
    public function testTheLargestFileIsReadWithFlatMemory(int $megabytesOfPictures): void
    {
        if (getenv('DATA_IMPORT_LARGE') !== '1') {
            self::markTestSkipped('Opt-in: DATA_IMPORT_LARGE=1 (a million cells and 50,000 rows take about a minute).');
        }

        self::bootKernel();
        $path = rtrim(sys_get_temp_dir(), '/') . '/msp-import-large-' . bin2hex(random_bytes(6)) . '.xlsx';

        try {
            RawXlsx::generate($path, 50_000, 20);

            if ($megabytesOfPictures > 0) {
                // What a workbook with photos carries: bytes that do not pack, which nobody opens
                $zip = new \ZipArchive();
                $zip->open($path);

                for ($picture = 0; $picture < $megabytesOfPictures; $picture++) {
                    $zip->addFromString('xl/media/image' . $picture . '.jpeg', random_bytes(self::MEBIBYTE));
                }

                $zip->close();
            }

            $bytes = (int) filesize($path);
            $importId = Uuid::uuid7()->toString();
            $bus = self::getContainer()->get(MessageBusInterface::class);
            $bus->dispatch(new StartImport($importId, PlayerFixture::PLAYER_REGULAR, 'largest.xlsx', $bytes));
            $stream = fopen($path, 'rb');
            self::assertIsResource($stream);
            self::getContainer()->get(Filesystem::class)->writeStream(ImportFileStorage::objectKey(PlayerFixture::PLAYER_REGULAR, $importId, DataImportFileKind::Xlsx), $stream);
            fclose($stream);
            $bus->dispatch(new ConfirmImportUploaded($importId, PlayerFixture::PLAYER_REGULAR));
        } finally {
            if (is_file($path)) {
                unlink($path);
            }
        }

        self::getContainer()->get(EntityManagerInterface::class)->clear();
        gc_collect_cycles();
        memory_reset_peak_usage();
        // The test's object store keeps the whole file in memory (production streams it from S3): not the job's
        $before = memory_get_usage();
        $wall = hrtime(true);
        $cpu = CpuClock::nanoseconds();

        // The test container's bus keeps every message it was handed for the profiler (TraceableMessageBus) - a
        // hundred batches of rows; production's does not. Forgotten after each one, as if it never kept them
        $forgetful = new class ($bus) implements MessageBusInterface {
            public function __construct(
                private readonly MessageBusInterface $bus,
            ) {
            }

            public function dispatch(object $message, array $stamps = []): Envelope
            {
                $envelope = $this->bus->dispatch($message, $stamps);

                if ($this->bus instanceof TraceableMessageBus) {
                    $this->bus->reset();
                }

                return $envelope;
            }
        };

        (new ReadImportFileHandler(
            $forgetful,
            self::getContainer()->get(ImportFileStorage::class),
            self::getContainer()->get(SpreadsheetReader::class),
            self::getContainer()->get(ImportProgress::class),
            self::getContainer()->get(ClockInterface::class),
            new NullLogger(),
        ))(new ReadImportFile($importId));

        $cpu = (CpuClock::nanoseconds() - $cpu) / 1e9;
        $wall = (hrtime(true) - $wall) / 1e9;
        $growth = memory_get_peak_usage() - $before;

        $database = self::getContainer()->get(Connection::class);
        $import = $database->fetchNumeric('SELECT status, read_error, sheets_read, rows_read FROM data_import WHERE id = :id', ['id' => $importId]);
        $stored = $database->fetchNumeric('SELECT COUNT(*), SUM(pg_column_size(cells)), SUM(LENGTH(cells::text)) FROM data_import_row WHERE import_id = :id', ['id' => $importId]);
        self::assertIsArray($stored);
        self::assertIsInt($stored[0]);
        self::assertIsNumeric($stored[1]);
        self::assertIsNumeric($stored[2]);

        fwrite(STDERR, sprintf(
            "\n50,000 rows × 20 columns, file %.1f MiB: wall %.1f s, CPU of this process %.1f s, peak memory growth %.1f MiB (peak %.1f MiB), rows stored %d in %.1f MiB (%.1f MiB as text), PHP %s\n",
            $bytes / self::MEBIBYTE,
            $wall,
            $cpu,
            $growth / self::MEBIBYTE,
            memory_get_peak_usage() / self::MEBIBYTE,
            $stored[0],
            (int) $stored[1] / self::MEBIBYTE,
            (int) $stored[2] / self::MEBIBYTE,
            PHP_VERSION,
        ));

        self::assertSame(['setting_up', null, 1, 50_000], $import);
        self::assertSame(50_000, $stored[0]);
    }
}
