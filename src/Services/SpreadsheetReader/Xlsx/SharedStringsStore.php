<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Services\SpreadsheetReader\Xlsx;

use OpenSpout\Reader\Exception\SharedStringNotFoundException;
use OpenSpout\Reader\XLSX\Manager\SharedStringsCaching\CachingStrategyInterface;

/**
 * Where OpenSpout keeps a workbook's shared strings (every distinct text of every sheet) while the sheets are read.
 *
 * OpenSpout's own two stores are bounded by the number of strings, not by their size: up to 10,000 strings - or all
 * of them, when PHP's memory limit looks roomy - stay in memory however long they are. A hostile workbook makes that
 * hundreds of megabytes. This one is bounded both ways: a small table stays in memory (the usual workbook), anything
 * more goes to one file in the reader's own temporary folder with an index of fixed-size records beside it, and a
 * lookup reads one string. Memory stays the same for a million strings.
 *
 * The interface is marked internal by OpenSpout, yet it is what its public CachingStrategyFactoryInterface - the
 * argument of its reader's constructor - must return. SharedStringsStoreTest pins the four calls the reader makes.
 */
// @phpstan-ignore class.implementsInternalInterface (the public factory interface of OpenSpout's reader returns it; pinned by SharedStringsStoreTest)
final class SharedStringsStore implements CachingStrategyInterface
{
    private const int INDEX_RECORD_BYTES = 12;
    private const int WRITE_BUFFER_BYTES = 65_536;
    /** Short strings read from the file are remembered in this many slots (a brand name is asked for again and again) */
    private const int REMEMBERED_SLOTS = 1024;
    private const int REMEMBERED_STRING_BYTES = 256;

    /** @var array<int, string> */
    private array $inMemory = [];
    private int $inMemoryBytes = 0;
    private int $count = 0;

    /** @var null|resource */
    private $data = null;
    /** @var null|resource */
    private $index = null;
    private string $dataBuffer = '';
    private string $indexBuffer = '';
    private int $dataBytes = 0;

    /** @var array<int, array{int, string}> slot => [string index, string] */
    private array $remembered = [];

    public function __construct(
        private readonly string $folder,
        private readonly int $maxStringsInMemory = 5_000,
        private readonly int $maxBytesInMemory = 1_048_576,
    ) {
    }

    public function __destruct()
    {
        $this->clearCache();
    }

    public function addStringForIndex(string $sharedString, int $sharedStringIndex): void
    {
        if ($sharedStringIndex !== $this->count) {
            throw new \LogicException('Shared strings arrive in order.');
        }

        $this->count++;

        if ($this->data === null) {
            $this->inMemory[$sharedStringIndex] = $sharedString;
            $this->inMemoryBytes += strlen($sharedString);

            if ($this->count > $this->maxStringsInMemory || $this->inMemoryBytes > $this->maxBytesInMemory) {
                $this->moveToFile();
            }

            return;
        }

        $this->append($sharedString);
    }

    public function closeCache(): void
    {
        $this->flush();
    }

    public function getStringAtIndex(int $sharedStringIndex): string
    {
        if ($this->data === null || $this->index === null) {
            return $this->inMemory[$sharedStringIndex] ?? throw new SharedStringNotFoundException(sprintf('Shared string not found for index: %d', $sharedStringIndex));
        }

        $slot = $sharedStringIndex % self::REMEMBERED_SLOTS;

        if (isset($this->remembered[$slot]) && $this->remembered[$slot][0] === $sharedStringIndex) {
            return $this->remembered[$slot][1];
        }

        if ($sharedStringIndex < 0 || $sharedStringIndex >= $this->count) {
            throw new SharedStringNotFoundException(sprintf('Shared string not found for index: %d', $sharedStringIndex));
        }

        $record = stream_get_contents($this->index, self::INDEX_RECORD_BYTES, $sharedStringIndex * self::INDEX_RECORD_BYTES);
        $position = is_string($record) && strlen($record) === self::INDEX_RECORD_BYTES ? unpack('Poffset/Vlength', $record) : false;

        if ($position === false || is_int($position['offset']) === false || is_int($position['length']) === false) {
            throw new SharedStringNotFoundException(sprintf('Shared string not found for index: %d', $sharedStringIndex));
        }

        $string = $position['length'] === 0 ? '' : stream_get_contents($this->data, $position['length'], $position['offset']);

        if (is_string($string) === false || strlen($string) !== $position['length']) {
            throw new SharedStringNotFoundException(sprintf('Shared string not found for index: %d', $sharedStringIndex));
        }

        if ($position['length'] <= self::REMEMBERED_STRING_BYTES) {
            $this->remembered[$slot] = [$sharedStringIndex, $string];
        }

        return $string;
    }

    public function clearCache(): void
    {
        $this->inMemory = [];
        $this->remembered = [];

        foreach ([$this->data, $this->index] as $handle) {
            if (is_resource($handle)) {
                $path = stream_get_meta_data($handle)['uri'] ?? null;
                fclose($handle);

                if (is_string($path)) {
                    @unlink($path);
                }
            }
        }

        $this->data = null;
        $this->index = null;
    }

    private function moveToFile(): void
    {
        $name = $this->folder . '/shared-strings-' . bin2hex(random_bytes(8));
        $data = fopen($name . '.data', 'x+b');
        $index = fopen($name . '.index', 'x+b');

        if ($data === false || $index === false) {
            throw new \RuntimeException('The shared strings can not be written to the temporary folder.');
        }

        $this->data = $data;
        $this->index = $index;

        foreach ($this->inMemory as $string) {
            $this->append($string);
        }

        $this->inMemory = [];
        $this->inMemoryBytes = 0;
    }

    private function append(string $string): void
    {
        $length = strlen($string);
        $this->indexBuffer .= pack('PV', $this->dataBytes, $length);
        $this->dataBuffer .= $string;
        $this->dataBytes += $length;

        if (strlen($this->dataBuffer) >= self::WRITE_BUFFER_BYTES || strlen($this->indexBuffer) >= self::WRITE_BUFFER_BYTES) {
            $this->flush();
        }
    }

    private function flush(): void
    {
        if ($this->data === null || $this->index === null) {
            return;
        }

        if (
            fwrite($this->data, $this->dataBuffer) !== strlen($this->dataBuffer)
            || fwrite($this->index, $this->indexBuffer) !== strlen($this->indexBuffer)
            || fflush($this->data) === false
            || fflush($this->index) === false
        ) {
            throw new \RuntimeException('The shared strings can not be written to the temporary folder.');
        }

        $this->dataBuffer = '';
        $this->indexBuffer = '';
    }
}
