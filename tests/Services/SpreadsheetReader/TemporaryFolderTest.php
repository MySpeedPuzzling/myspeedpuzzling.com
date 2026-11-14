<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Services\SpreadsheetReader;

use PHPUnit\Framework\TestCase;
use SpeedPuzzling\Web\Exceptions\SpreadsheetReaderFailed;
use SpeedPuzzling\Web\Services\SpreadsheetReader\SpreadsheetReader;
use SpeedPuzzling\Web\Services\SpreadsheetReader\TemporaryFolder;
use SpeedPuzzling\Web\Value\Spreadsheet\SpreadsheetFormat;

final class TemporaryFolderTest extends TestCase
{
    private string $base = '';

    protected function setUp(): void
    {
        $this->base = sys_get_temp_dir() . '/folder-test-' . bin2hex(random_bytes(6));
    }

    protected function tearDown(): void
    {
        foreach (glob($this->base . '/*/*') ?: [] as $file) {
            is_link($file) || is_file($file) ? @unlink($file) : @rmdir($file);
        }

        foreach (glob($this->base . '/*') ?: [] as $entry) {
            is_dir($entry) && is_link($entry) === false ? @rmdir($entry) : @unlink($entry);
        }

        @rmdir($this->base);
    }

    public function testTheFolderIsMadeWhenSomethingIsWrittenAndGoneWhenItIsRemoved(): void
    {
        $folder = new TemporaryFolder($this->base);

        // Not even the base is made before something is written
        self::assertDirectoryDoesNotExist($this->base);

        $first = $folder->newFile('csv');
        $second = $folder->newFile('csv');

        self::assertNotSame($first, $second);
        self::assertSame(dirname($first), dirname($second));
        self::assertMatchesRegularExpression('~^' . preg_quote($this->base, '~') . '/msp-spreadsheet-[0-9a-f]{16}/[0-9a-f]{16}\.csv$~', $first);
        self::assertSame(0700, fileperms(dirname($first)) & 0777);
        // The path is only a name: the caller creates the file, and only if it is not there
        self::assertFileDoesNotExist($first);

        file_put_contents($first, 'x');
        mkdir(dirname($first) . '/nested');
        file_put_contents(dirname($first) . '/nested/y', 'y');
        $folder->remove();
        $folder->remove();

        self::assertSame([], glob($this->base . '/*'));
    }

    public function testAnUncreatableFolderIsTheServersFault(): void
    {
        mkdir($this->base);
        file_put_contents($this->base . '/a-file', 'not a folder');

        $this->expectException(SpreadsheetReaderFailed::class);
        (new TemporaryFolder($this->base . '/a-file/below'))->newFile('csv');
    }

    public function testLeftoversOfReadersThatNeverClosedArePruned(): void
    {
        mkdir($this->base);
        $reader = new SpreadsheetReader($this->base);
        $now = new \DateTimeImmutable('2026-11-14 12:00:00 UTC');

        // What a killed worker left behind yesterday, with its files
        $old = $this->base . '/msp-spreadsheet-00000000000000aa';
        mkdir($old, 0700);
        file_put_contents($old . '/1234.strings', 'x');
        touch($old, $now->modify('-26 hours')->getTimestamp());
        // A reader that is at work right now
        $fresh = $this->base . '/msp-spreadsheet-00000000000000bb';
        mkdir($fresh, 0700);
        touch($fresh, $now->modify('-5 minutes')->getTimestamp());
        // Things that are not the reader's: another name, a file, a link that looks like a reader's folder
        $foreign = $this->base . '/somebody-elses-folder';
        mkdir($foreign);
        touch($foreign, $now->modify('-30 days')->getTimestamp());
        file_put_contents($this->base . '/msp-spreadsheet-00000000000000cc', 'a file');
        touch($this->base . '/msp-spreadsheet-00000000000000cc', $now->modify('-30 days')->getTimestamp());
        mkdir($this->base . '/msp-spreadsheet-not-hex-at-all-x');
        touch($this->base . '/msp-spreadsheet-not-hex-at-all-x', $now->modify('-30 days')->getTimestamp());
        symlink($foreign, $this->base . '/msp-spreadsheet-00000000000000dd');

        self::assertSame(1, $reader->pruneLeftovers($now->modify('-24 hours')));

        self::assertDirectoryDoesNotExist($old);
        self::assertDirectoryExists($fresh);
        self::assertDirectoryExists($foreign);
        self::assertFileExists($this->base . '/msp-spreadsheet-00000000000000cc');
        self::assertDirectoryExists($this->base . '/msp-spreadsheet-not-hex-at-all-x');
        self::assertTrue(is_link($this->base . '/msp-spreadsheet-00000000000000dd'));

        // Nothing left to prune; a folder that is not there is nothing to prune either
        self::assertSame(0, $reader->pruneLeftovers($now->modify('-24 hours')));
        self::assertSame(0, (new SpreadsheetReader($this->base . '/nowhere'))->pruneLeftovers($now));
        self::assertSame(1, $reader->pruneLeftovers($now));
    }

    public function testAFolderInUseSaysSoAndIsNotTakenForAnAbandonedOne(): void
    {
        mkdir($this->base);
        $now = new \DateTimeImmutable('2026-11-14 12:00:00 UTC');
        $folder = new TemporaryFolder($this->base, keepAliveNanoseconds: 0);
        $path = dirname($folder->newFile('strings'));

        // A read that has been going on since yesterday …
        touch($path, $now->modify('-26 hours')->getTimestamp());
        self::assertSame($now->modify('-26 hours')->getTimestamp(), filemtime($path));

        // … and is still going on
        $folder->keepAlive();
        clearstatcache();

        self::assertGreaterThan($now->modify('-1 hour')->getTimestamp(), filemtime($path));
        self::assertSame(0, TemporaryFolder::prune($this->base, $now->modify('-24 hours')));

        // Once a minute is enough
        $patient = new TemporaryFolder($this->base);
        $other = dirname($patient->newFile('strings'));
        touch($other, $now->modify('-26 hours')->getTimestamp());
        $patient->keepAlive();
        clearstatcache();

        self::assertSame($now->modify('-26 hours')->getTimestamp(), filemtime($other));

        $folder->remove();
        $patient->remove();
    }

    public function testAReaderWithoutAFolderOfItsOwnUsesOneUnderTheSystems(): void
    {
        $path = $this->base . '.csv';
        file_put_contents($path, "\xFF\xFE" . mb_convert_encoding("a;b\n1;2\n", 'UTF-16LE', 'UTF-8'));
        $default = sys_get_temp_dir() . '/' . SpreadsheetReader::DEFAULT_TEMPORARY_FOLDER;

        try {
            $workbook = (new SpreadsheetReader())->open($path, SpreadsheetFormat::Csv);

            // The rewritten copy stands in the reader's own folder - not loose in the system's
            self::assertDirectoryExists($default);
            self::assertSame(0700, fileperms($default) & 0777);
            self::assertCount(2, iterator_to_array($workbook->rows($workbook->sheets()[0]), false));

            $workbook->close();
        } finally {
            unlink($path);
        }
    }
}
