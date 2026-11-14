<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Value\DataImport;

use DateTimeImmutable;
use DateTimeZone;
use LogicException;

/**
 * One cell as the value readers take it (docs/features/data-import/value-readers.md "Input"): a text, or a value the
 * spreadsheet already typed - a number, the seconds of a duration or of a time of day, a date with how exactly its
 * format shows it, yes/no. The readers know nothing about the spreadsheet reader; the stage that reads rows adapts its
 * cells to this.
 */
final readonly class CellInput
{
    private function __construct(
        public CellInputKind $kind,
        private null|string|int|float|bool|DateTimeImmutable $value = null,
        /** Dates only: what the cell's format shows */
        public DatePrecision $precision = DatePrecision::Day,
        /** Dates only: false when the cell's format hides the year ("m/d") - the value's year was never seen */
        public bool $yearKnown = true,
    ) {
    }

    public static function blank(): self
    {
        return new self(CellInputKind::Blank);
    }

    /**
     * A text as typed - a text of nothing but whitespace is read as "no value" by every reader.
     */
    public static function text(string $text): self
    {
        return new self(CellInputKind::Text, $text);
    }

    public static function number(int|float $number): self
    {
        return new self(CellInputKind::Number, $number);
    }

    public static function duration(int $seconds): self
    {
        return new self(CellInputKind::Duration, $seconds);
    }

    public static function timeOfDay(int $seconds): self
    {
        return new self(CellInputKind::TimeOfDay, $seconds);
    }

    /**
     * @param DateTimeImmutable $date the calendar day the cell holds; its time and zone are dropped
     */
    public static function date(
        DateTimeImmutable $date,
        DatePrecision $precision = DatePrecision::Day,
        bool $yearKnown = true,
    ): self {
        $day = DateTimeImmutable::createFromFormat('!Y-m-d', $date->format('Y-m-d'), new DateTimeZone('UTC'));
        assert($day !== false);

        return new self(CellInputKind::Date, $day, $precision, $yearKnown);
    }

    public static function boolean(bool $value): self
    {
        return new self(CellInputKind::Boolean, $value);
    }

    public function asText(): string
    {
        return is_string($this->value) ? $this->value : throw $this->not('a text');
    }

    public function asNumber(): int|float
    {
        return $this->kind === CellInputKind::Number && (is_int($this->value) || is_float($this->value))
            ? $this->value
            : throw $this->not('a number');
    }

    /**
     * The seconds of a duration or of a time of day.
     */
    public function asSeconds(): int
    {
        return ($this->kind === CellInputKind::Duration || $this->kind === CellInputKind::TimeOfDay) && is_int($this->value)
            ? $this->value
            : throw $this->not('a duration or a time of day');
    }

    public function asDate(): DateTimeImmutable
    {
        return $this->value instanceof DateTimeImmutable ? $this->value : throw $this->not('a date');
    }

    public function asBoolean(): bool
    {
        return is_bool($this->value) ? $this->value : throw $this->not('a yes/no value');
    }

    private function not(string $what): LogicException
    {
        return new LogicException(sprintf('A %s cell is not %s.', $this->kind->value, $what));
    }
}
