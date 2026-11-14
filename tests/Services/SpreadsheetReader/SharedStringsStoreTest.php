<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Services\SpreadsheetReader;

use OpenSpout\Reader\Exception\SharedStringNotFoundException;
use PHPUnit\Framework\TestCase;
use SpeedPuzzling\Web\Services\SpreadsheetReader\Xlsx\SharedStringsStore;

/**
 * The store is handed to OpenSpout through an interface it calls internal: these are the four calls its reader makes.
 */
final class SharedStringsStoreTest extends TestCase
{
    private string $folder = '';

    protected function setUp(): void
    {
        $this->folder = sys_get_temp_dir() . '/store-test-' . bin2hex(random_bytes(6));
        mkdir($this->folder, 0700);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->folder . '/*') ?: [] as $file) {
            @unlink($file);
        }

        @rmdir($this->folder);
    }

    public function testASmallTableStaysInMemory(): void
    {
        $store = new SharedStringsStore($this->folder);
        $store->addStringForIndex('first', 0);
        $store->addStringForIndex('', 1);
        $store->addStringForIndex('third', 2);
        $store->closeCache();

        self::assertSame('first', $store->getStringAtIndex(0));
        self::assertSame('', $store->getStringAtIndex(1));
        self::assertSame('third', $store->getStringAtIndex(2));
        self::assertSame([], glob($this->folder . '/*'));

        $this->expectException(SharedStringNotFoundException::class);
        $store->getStringAtIndex(3);
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
            $store->addStringForIndex($strings[$index], $index);
        }

        $store->closeCache();

        self::assertCount(2, (array) glob($this->folder . '/*'));

        // Out of order, and each one twice
        foreach ([2_999, 0, 1_500, 1, 2, 3, 1_500, 2_999, 701, 702] as $index) {
            self::assertSame($strings[$index], $store->getStringAtIndex($index));
        }

        for ($index = 0; $index < 3_000; $index++) {
            self::assertSame($strings[$index], $store->getStringAtIndex($index));
        }

        try {
            $store->getStringAtIndex(3_000);
            self::fail('A string that was never added was found.');
        } catch (SharedStringNotFoundException) {
        }

        try {
            $store->getStringAtIndex(-1);
            self::fail('A string that was never added was found.');
        } catch (SharedStringNotFoundException) {
        }

        $store->clearCache();

        self::assertSame([], glob($this->folder . '/*'));
    }

    public function testAFewLongStringsGoToAFileToo(): void
    {
        $store = new SharedStringsStore($this->folder, maxBytesInMemory: 10_000);
        $long = str_repeat('A long note. ', 1_000);

        $store->addStringForIndex($long, 0);
        $store->addStringForIndex('short', 1);
        $store->closeCache();

        self::assertCount(2, (array) glob($this->folder . '/*'));
        self::assertSame($long, $store->getStringAtIndex(0));
        self::assertSame('short', $store->getStringAtIndex(1));

        unset($store);

        // Forgotten without a clearCache(): the files go with the store
        self::assertSame([], glob($this->folder . '/*'));
    }
}
