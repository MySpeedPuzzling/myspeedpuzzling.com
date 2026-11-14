<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Value\Spreadsheet;

/**
 * One cell with a value, as SpreadsheetReader hands it over (docs/features/data-import/reader.md "Cell kinds").
 *
 * $value by $kind: Text = string (as typed, nothing trimmed) · Number = int|float · Date = DateTimeImmutable (midnight,
 * UTC) · Duration and TimeOfDay = seconds (int) · Boolean = bool · Error = the error code ("#DIV/0!").
 * $shown is a stable, readable text of what the player saw - not Excel's exact rendering.
 */
final readonly class ReadCell
{
    private function __construct(
        public CellKind $kind,
        public string|int|float|bool|\DateTimeImmutable $value,
        public string $shown,
        /** The value is what the spreadsheet saved as the formula's result - a formula is never calculated here */
        public bool $isFormula = false,
        /** Dates only */
        public null|DatePrecision $precision = null,
        /** Dates only: false when the cell's format hides the year ("m/d") - the value's year was never seen */
        public bool $yearShown = true,
        /** Dates only: the time of day in seconds when the format shows one next to the date */
        public null|int $timeSeconds = null,
        /** The address the cell links to, when it has a link that is not its own text */
        public null|string $hyperlink = null,
    ) {
    }

    public static function text(string $text, bool $isFormula = false): self
    {
        return new self(CellKind::Text, $text, $text, $isFormula);
    }

    public static function number(int|float $number, bool $isFormula = false, bool $percent = false): self
    {
        $shown = $percent ? self::numberText($number * 100) . '%' : self::numberText($number);

        return new self(CellKind::Number, $number, $shown, $isFormula);
    }

    /**
     * @param \DateTimeImmutable $date the calendar day; its time and zone are dropped
     */
    public static function date(
        \DateTimeImmutable $date,
        DatePrecision $precision = DatePrecision::Day,
        bool $yearShown = true,
        null|int $timeSeconds = null,
        bool $isFormula = false,
    ): self {
        $day = \DateTimeImmutable::createFromFormat('!Y-m-d', $date->format('Y-m-d'), new \DateTimeZone('UTC'));
        \assert($day !== false);

        $shown = match (true) {
            $precision === DatePrecision::Year => $day->format('Y'),
            $precision === DatePrecision::Month => $yearShown ? $day->format('Y-m') : $day->format('--m'),
            default => $yearShown ? $day->format('Y-m-d') : $day->format('--m-d'),
        };

        if ($timeSeconds !== null) {
            $shown .= ' ' . self::clockText($timeSeconds, 2);
        }

        return new self(CellKind::Date, $day, $shown, $isFormula, $precision, $yearShown, $timeSeconds);
    }

    public static function duration(int $seconds, bool $isFormula = false): self
    {
        return new self(CellKind::Duration, $seconds, self::clockText($seconds, 1), $isFormula);
    }

    public static function timeOfDay(int $seconds, bool $isFormula = false): self
    {
        return new self(CellKind::TimeOfDay, $seconds, self::clockText($seconds, 1), $isFormula);
    }

    public static function boolean(bool $value, bool $isFormula = false): self
    {
        return new self(CellKind::Boolean, $value, $value ? 'TRUE' : 'FALSE', $isFormula);
    }

    public static function error(string $code, bool $isFormula = false): self
    {
        return new self(CellKind::Error, $code, $code, $isFormula);
    }

    public function withHyperlink(string $url): self
    {
        return new self(
            $this->kind,
            $this->value,
            $this->shown,
            $this->isFormula,
            $this->precision,
            $this->yearShown,
            $this->timeSeconds,
            $url,
        );
    }

    /**
     * The text of a Text cell, the code of an Error cell.
     */
    public function asText(): string
    {
        return is_string($this->value) ? $this->value : throw $this->not('text');
    }

    public function asNumber(): int|float
    {
        return $this->kind === CellKind::Number && (is_int($this->value) || is_float($this->value))
            ? $this->value
            : throw $this->not('a number');
    }

    public function asDate(): \DateTimeImmutable
    {
        return $this->value instanceof \DateTimeImmutable ? $this->value : throw $this->not('a date');
    }

    /**
     * Seconds of a Duration or TimeOfDay cell.
     */
    public function asSeconds(): int
    {
        return ($this->kind === CellKind::Duration || $this->kind === CellKind::TimeOfDay) && is_int($this->value)
            ? $this->value
            : throw $this->not('a duration or a time of day');
    }

    public function asBoolean(): bool
    {
        return is_bool($this->value) ? $this->value : throw $this->not('a yes/no value');
    }

    /**
     * A number without float noise: 0.1 + 0.2 is "0.3", a whole number has no decimal point (15 significant digits,
     * like a spreadsheet).
     */
    public static function numberText(int|float $number): string
    {
        if (is_int($number)) {
            return (string) $number;
        }

        if (is_nan($number) || is_infinite($number)) {
            return '';
        }

        if ($number === 0.0) {
            return '0';
        }

        $magnitude = (int) floor(log10(abs($number)));

        if ($magnitude >= 15 || $magnitude < -15) {
            return sprintf('%.15G', $number);
        }

        $text = number_format($number, max(0, 14 - $magnitude), '.', '');

        if (str_contains($text, '.')) {
            $text = rtrim(rtrim($text, '0'), '.');
        }

        return $text === '-0' ? '0' : $text;
    }

    /**
     * "0:56:04", "25:30:00", "-1:00:00" - hours never wrap, minutes and seconds always have two digits.
     */
    private static function clockText(int $seconds, int $hourDigits): string
    {
        $absolute = abs($seconds);

        return sprintf(
            '%s%0' . $hourDigits . 'd:%02d:%02d',
            $seconds < 0 ? '-' : '',
            intdiv($absolute, 3600),
            intdiv($absolute % 3600, 60),
            $absolute % 60,
        );
    }

    private function not(string $what): \LogicException
    {
        return new \LogicException(sprintf('A %s cell is not %s.', $this->kind->value, $what));
    }
}
