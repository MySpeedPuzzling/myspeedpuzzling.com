<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Services\SpreadsheetReader;

use PHPUnit\Framework\TestCase;
use SpeedPuzzling\Web\Exceptions\SpreadsheetReaderFailed;
use SpeedPuzzling\Web\Services\SpreadsheetReader\TemporaryFolder;
use SpeedPuzzling\Web\Services\SpreadsheetReader\Xlsx\SharedStringsStore;

final class SharedStringsStoreTest extends TestCase
{
    private string $base = '';
    private TemporaryFolder $folder;

    protected function setUp(): void
    {
        $this->base = sys_get_temp_dir() . '/store-test-' . bin2hex(random_bytes(6));
        mkdir($this->base, 0700);
        $this->folder = new TemporaryFolder($this->base);
    }

    protected function tearDown(): void
    {
        $this->folder->remove();
        @rmdir($this->base);
    }

    public function testASmallTableStaysInMemory(): void
    {
        $store = new SharedStringsStore($this->folder);
        $store->add('first');
        $store->add('');
        $store->add('third');
        $store->finish();

        self::assertSame(3, $store->count());
        self::assertSame('first', $store->get(0));
        self::assertSame('', $store->get(1));
        self::assertSame('third', $store->get(2));
        self::assertNull($store->get(3));
        self::assertNull($store->get(-1));
        // Nothing was written: not even the folder was made
        self::assertSame([], glob($this->base . '/*'));
    }

    public function testMoreStringsThanFitGoToAFileAndComeBackTheSame(): void
    {
        $store = new SharedStringsStore($this->folder, maxStringsInMemory: 10);
        $strings = [];

        for ($index = 0; $index < 3_000; $index++) {
            $strings[$index] = match ($index % 4) {
                0 => '',
                1 => "Line one\nline two " . $index,
                2 => str_repeat('ž', $index % 700),
                default => 'Puzzle ' . $index,
            };
            $store->add($strings[$index]);
        }

        $store->finish();

        self::assertCount(2, $this->files());

        // Out of order, and each one twice
        foreach ([2_999, 0, 1_500, 1, 2, 3, 1_500, 2_999, 701, 702] as $index) {
            self::assertSame($strings[$index], $store->get($index));
        }

        for ($index = 0; $index < 3_000; $index++) {
            self::assertSame($strings[$index], $store->get($index));
        }

        self::assertNull($store->get(3_000));
        self::assertNull($store->get(-1));

        $store->clear();

        self::assertSame([], $this->files());
    }

    public function testAFewLongStringsGoToAFileToo(): void
    {
        $store = new SharedStringsStore($this->folder, maxBytesInMemory: 10_000);
        $long = str_repeat('A long note. ', 1_000);

        $store->add($long);
        $store->add('short');
        $store->finish();

        self::assertCount(2, $this->files());
        self::assertSame($long, $store->get(0));
        self::assertSame('short', $store->get(1));

        unset($store);

        // Forgotten without a clear(): the files go with the store
        self::assertSame([], $this->files());
    }

    public function testAFolderThatCanNotBeWrittenFailsLoudly(): void
    {
        file_put_contents($this->base . '/a-file.txt', 'not a folder');
        $store = new SharedStringsStore(new TemporaryFolder($this->base . '/a-file.txt/under-a-file'), maxStringsInMemory: 2);
        $store->add('one');
        $store->add('two');

        try {
            $store->add('three');
            self::fail('Strings were written where nothing can be written.');
        } catch (SpreadsheetReaderFailed $e) {
            self::assertStringContainsString('temporary folder', $e->getMessage());
        } finally {
            unlink($this->base . '/a-file.txt');
        }
    }

    /**
     * @return list<string>
     */
    private function files(): array
    {
        $files = glob($this->base . '/msp-spreadsheet-*/*');

        return $files === false ? [] : $files;
    }
}
