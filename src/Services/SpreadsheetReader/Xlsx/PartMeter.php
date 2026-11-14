<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Services\SpreadsheetReader\Xlsx;

/**
 * The one hook every byte of a part passes on its way to the XML parser (PartMeterFilter) - whatever loop asked for
 * it, also while the parser skips a subtree by itself. Per chunk of 8 KiB it
 *
 * - counts the bytes the part really unpacks to: against what the archive says the part holds (libzip hands out
 *   whatever the compressed stream holds, whatever size its header declares) and against the workbook's budget;
 * - looks at the clock: the workbook's seconds are a budget too;
 * - counts what the parser complained about since the last chunk;
 * - and lets XmlFloodScanner see the chunk before the parser does.
 */
final class PartMeter
{
    public const string LONGER_THAN_DECLARED = 'longer';
    public const string OVER_BUDGET = 'budget';
    public const string OUT_OF_TIME = 'time';
    public const string TOO_MANY_PARSER_ERRORS = 'errors';

    /** A workbook with more complaints of the parser than this (undeclared prefixes, mostly) is not read on */
    private const int MAX_PARSER_ERRORS = 100;

    public int $read = 0;
    /** @var null|string one of the constants above, or a reason of XmlFloodScanner */
    public null|string $failure = null;

    public function __construct(
        private readonly int $declared,
        private readonly ReadBudget $budget,
        private readonly XmlFloodScanner $scanner,
    ) {
    }

    /**
     * @return bool false: reading stops here, the chunk does not reach the parser
     */
    public function pass(string $chunk): bool
    {
        $bytes = strlen($chunk);
        $budget = $this->budget;
        $this->read += $bytes;
        $budget->read += $bytes;
        $budget->remaining -= $bytes;

        $this->failure = match (true) {
            $this->read > $this->declared => self::LONGER_THAN_DECLARED,
            $budget->remaining < 0 => self::OVER_BUDGET,
            $budget->outOfTime() => self::OUT_OF_TIME,
            $this->parserComplainsTooMuch() => self::TOO_MANY_PARSER_ERRORS,
            default => $this->scanner->scan($chunk),
        };

        return $this->failure === null;
    }

    private function parserComplainsTooMuch(): bool
    {
        $this->budget->countParserErrors();

        return $this->budget->parserErrors > self::MAX_PARSER_ERRORS;
    }
}
