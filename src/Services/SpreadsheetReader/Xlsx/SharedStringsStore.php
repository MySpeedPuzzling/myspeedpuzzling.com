<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Services\SpreadsheetReader\Xlsx;

use SpeedPuzzling\Web\Exceptions\SpreadsheetReaderFailed;
use SpeedPuzzling\Web\Services\SpreadsheetReader\TemporaryFolder;

/**
 * Where a workbook's shared strings (every distinct text of every sheet) are kept while its rows are read. Bounded
 * both ways: a small table stays in memory (the usual workbook), anything more goes to one file in the reader's
 * temporary folder with an index of fixed-size records beside it, and a lookup reads one string. Memory stays the
 * same for a million strings.
 */
final class SharedStringsStore
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
    /** @var list<string> */
    private array $files = [];
    private string $dataBuffer = '';
    private string $indexBuffer = '';
    private int $dataBytes = 0;

    /** @var array<int, array{int, string}> slot => [string index, string] */
    private array $remembered = [];

    public function __construct(
        private readonly TemporaryFolder $folder,
        private readonly int $maxStringsInMemory = 5_000,
        private readonly int $maxBytesInMemory = 1_048_576,
    ) {
    }

    public function __destruct()
    {
        $this->clear();
    }

    public function count(): int
    {
        return $this->count;
    }

    /**
     * Adds the next string (they are numbered in the order they are added).
     */
    public function add(string $string): void
    {
        $this->count++;

        if ($this->data === null) {
            $this->inMemory[] = $string;
            $this->inMemoryBytes += strlen($string);

            if ($this->count > $this->maxStringsInMemory || $this->inMemoryBytes > $this->maxBytesInMemory) {
                $this->moveToFile();
            }

            return;
        }

        $this->append($string);
    }

    /**
     * After the last string: what is still in the write buffers goes to the file.
     */
    public function finish(): void
    {
        $this->flush();
    }

    /**
     * @return null|string null: there is no such string
     */
    public function get(int $number): null|string
    {
        if ($number < 0 || $number >= $this->count) {
            return null;
        }

        if ($this->data === null || $this->index === null) {
            return $this->inMemory[$number] ?? null;
        }

        $slot = $number % self::REMEMBERED_SLOTS;

        if (isset($this->remembered[$slot]) && $this->remembered[$slot][0] === $number) {
            return $this->remembered[$slot][1];
        }

        $record = stream_get_contents($this->index, self::INDEX_RECORD_BYTES, $number * self::INDEX_RECORD_BYTES);
        $position = is_string($record) && strlen($record) === self::INDEX_RECORD_BYTES ? unpack('Poffset/Vlength', $record) : false;

        if ($position === false || is_int($position['offset']) === false || is_int($position['length']) === false) {
            throw new SpreadsheetReaderFailed('The shared strings can not be read back from the temporary folder.');
        }

        $string = $position['length'] === 0 ? '' : stream_get_contents($this->data, $position['length'], $position['offset']);

        if (is_string($string) === false || strlen($string) !== $position['length']) {
            throw new SpreadsheetReaderFailed('The shared strings can not be read back from the temporary folder.');
        }

        if ($position['length'] <= self::REMEMBERED_STRING_BYTES) {
            $this->remembered[$slot] = [$number, $string];
        }

        return $string;
    }

    /**
     * Never throws.
     */
    public function clear(): void
    {
        $this->inMemory = [];
        $this->remembered = [];
        $this->count = 0;

        foreach ([$this->data, $this->index] as $handle) {
            if (is_resource($handle)) {
                @fclose($handle);
            }
        }

        foreach ($this->files as $file) {
            @unlink($file);
        }

        $this->data = null;
        $this->index = null;
        $this->files = [];
    }

    private function moveToFile(): void
    {
        $dataFile = $this->folder->newFile('strings');
        $indexFile = $this->folder->newFile('index');
        // "x": the file must not exist - nothing that is already in the folder is ever written into
        $data = @fopen($dataFile, 'x+b');
        $index = @fopen($indexFile, 'x+b');

        if ($data === false || $index === false) {
            throw new SpreadsheetReaderFailed('The shared strings can not be written to the temporary folder.');
        }

        $this->data = $data;
        $this->index = $index;
        $this->files = [$dataFile, $indexFile];

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

        // A full disk fails here, loudly - never a shorter file
        if (
            @fwrite($this->data, $this->dataBuffer) !== strlen($this->dataBuffer)
            || @fwrite($this->index, $this->indexBuffer) !== strlen($this->indexBuffer)
            || @fflush($this->data) === false
            || @fflush($this->index) === false
        ) {
            throw new SpreadsheetReaderFailed('The shared strings can not be written to the temporary folder.');
        }

        $this->dataBuffer = '';
        $this->indexBuffer = '';
    }
}
