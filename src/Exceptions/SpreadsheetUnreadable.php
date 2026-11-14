<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Exceptions;

use SpeedPuzzling\Web\Value\Spreadsheet\SpreadsheetUnreadableReason;

/**
 * A file SpreadsheetReader does not read: over a limit, not a spreadsheet, damaged, protected by a password, or in a
 * format that is not supported (docs/features/data-import/reader.md "Limits and reasons"). The only exception the
 * reader lets out for a bad file - whatever a library tripped over is its `previous`.
 *
 * The message is for the logs and never holds a cell's content; the player is told by $reason.
 */
final class SpreadsheetUnreadable extends \RuntimeException
{
    public function __construct(
        public readonly SpreadsheetUnreadableReason $reason,
        string $detail,
        null|\Throwable $previous = null,
    ) {
        parent::__construct(sprintf('The spreadsheet can not be read (%s): %s', $reason->value, $detail), 0, $previous);
    }

    public static function tooLarge(string $detail): self
    {
        return new self(SpreadsheetUnreadableReason::TooLarge, $detail);
    }

    public static function tooManySheets(int $max): self
    {
        return new self(SpreadsheetUnreadableReason::TooManySheets, sprintf('more than %d sheets', $max));
    }

    public static function tooManyRows(int $max): self
    {
        return new self(SpreadsheetUnreadableReason::TooManyRows, sprintf('more than %d rows', $max));
    }

    public static function tooManyColumns(int $max): self
    {
        return new self(SpreadsheetUnreadableReason::TooManyColumns, sprintf('a value beyond column %d', $max));
    }

    public static function tooManyCells(int $max): self
    {
        return new self(SpreadsheetUnreadableReason::TooManyCells, sprintf('more than %d cells', $max));
    }

    public static function notASpreadsheet(string $detail, null|\Throwable $previous = null): self
    {
        return new self(SpreadsheetUnreadableReason::NotASpreadsheet, $detail, $previous);
    }

    public static function damaged(string $detail, null|\Throwable $previous = null): self
    {
        return new self(SpreadsheetUnreadableReason::Damaged, $detail, $previous);
    }

    public static function passwordProtected(string $detail): self
    {
        return new self(SpreadsheetUnreadableReason::PasswordProtected, $detail);
    }

    public static function unsupportedFormat(string $detail): self
    {
        return new self(SpreadsheetUnreadableReason::UnsupportedFormat, $detail);
    }
}
