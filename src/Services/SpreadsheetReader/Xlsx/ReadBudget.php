<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Services\SpreadsheetReader\Xlsx;

/**
 * What an opened workbook may still cost: bytes of XML unpacked and parsed, and seconds spent reading - one budget for
 * every pass over every part (SpreadsheetLimits::$maxReadBytes, ::$maxReadSeconds).
 *
 * Time is counted only while the reader works: every entry point of the workbook brackets its work with enter() and
 * leave(), so what the caller does with a row between two rows is not the reader's time. While the reader works,
 * - warnings PHP raises from inside the XML reader (a compressed part that is corrupt: "Zip stream error") are taken
 *   here and never reach the application's error handler - the reader finds out by the call's result and says
 *   "damaged" itself, the same in every environment;
 * - the XML parser keeps its complaints in a list instead of raising a warning for each (a part of a million
 *   elements with an undeclared prefix would raise a million); PartMeter counts and empties it.
 */
final class ReadBudget
{
    public int $read = 0;
    /** Complaints of the XML parser that were not the end of a part */
    public int $parserErrors = 0;
    /** The parser gave up on a text of more than its own limit */
    public bool $hugeText = false;

    private int $spent = 0;
    private null|int $enteredAt = null;
    private int $depth = 0;
    private bool $parserErrorsWereInternal = false;

    public function __construct(
        public int $remaining,
        private readonly int $maxNanoseconds,
    ) {
    }

    public function enter(): void
    {
        if ($this->depth++ > 0) {
            return;
        }

        $this->enteredAt = hrtime(true);
        $this->parserErrorsWereInternal = libxml_use_internal_errors(true);

        $previous = null;
        $previous = set_error_handler(
            static function (int $level, string $message, string $file = '', int $line = 0) use (&$previous): bool {
                if (str_starts_with($message, 'XMLReader::')) {
                    return true;
                }

                return is_callable($previous) ? (bool) $previous($level, $message, $file, $line) : false;
            },
        );
    }

    public function leave(): void
    {
        if ($this->depth === 0 || --$this->depth > 0) {
            return;
        }

        restore_error_handler();
        $this->countParserErrors();
        libxml_use_internal_errors($this->parserErrorsWereInternal);

        if ($this->enteredAt !== null) {
            $this->spent += hrtime(true) - $this->enteredAt;
            $this->enteredAt = null;
        }
    }

    /**
     * Takes what the parser complained about since the last time out of its list, and counts it.
     */
    public function countParserErrors(): void
    {
        $errors = libxml_get_errors();

        if ($errors === []) {
            return;
        }

        foreach ($errors as $error) {
            $this->hugeText = $this->hugeText || stripos($error->message, 'huge') !== false;
        }

        $this->parserErrors += count($errors);
        libxml_clear_errors();
    }

    public function nanoseconds(): int
    {
        return $this->spent + ($this->enteredAt === null ? 0 : hrtime(true) - $this->enteredAt);
    }

    public function outOfTime(): bool
    {
        return $this->nanoseconds() > $this->maxNanoseconds;
    }
}
