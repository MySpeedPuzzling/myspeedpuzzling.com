<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Value\DataImport;

use DateTimeImmutable;
use SpeedPuzzling\Web\Value\DatePrecision;

/**
 * What ImportDateReader made of one cell of a date column (docs/features/data-import/value-readers.md "Dates").
 *
 * $date is midnight UTC of the day - of the first day of the month or year when only that much is known ($precision).
 * It is null while the value has no single reading: nothing, unreadable, numbers that are a date in several orders
 * ($possibleOrders - inOrder() gives the reading of one), or a short date whose last number is a day or a year
 * ($possibleShortMeanings - shortReading() gives each).
 *
 * When $yearKnown is false the year of $date was never seen and must not be used: a typed cell carries the year its
 * format hides, a text like "1/3" the placeholder year 2000 (a leap year, so 29 February stays a date).
 * ImportDateReader::withYear() puts the right one in.
 */
final readonly class ReadDate
{
    /** The year a text date without a year is built with */
    public const int PLACEHOLDER_YEAR = 2000;

    /**
     * @param list<DateOrder> $possibleOrders The orders in which a date written in numbers is a real date - empty for a
     *        value whose order is not a question (a typed date, a month's name, a four-digit year first), and for a
     *        short date still open between day and year (its day reading carries them)
     * @param list<DateProblem> $problems Of $date - empty while there is no single reading
     * @param list<ShortDateMeaning> $possibleShortMeanings For a short date ("Jan 23", "10/23", "Jan 5", "Feb 30"): what
     *        its last number can be. One entry = the value has one reading, but it still tells the column what the
     *        others mean; empty for every other value
     * @param array<string, array{date: DateTimeImmutable, problems: list<DateProblem>}> $byOrder
     */
    private function __construct(
        public DateCertainty $certainty,
        public null|DateTimeImmutable $date = null,
        public DatePrecision $precision = DatePrecision::Day,
        public bool $yearKnown = true,
        public array $possibleOrders = [],
        public array $problems = [],
        /** A two-digit year was read: 00-69 as 2000-2069, 70-99 as 1970-1999 */
        public bool $centuryAssumed = false,
        /** From a date cell - the spreadsheet made it a date, in whatever order it took the typed text for */
        public bool $typed = false,
        public array $possibleShortMeanings = [],
        private array $byOrder = [],
        private null|self $asDayOfMonth = null,
        private null|self $asMonthOfYear = null,
    ) {
    }

    public static function noDate(): self
    {
        return new self(DateCertainty::NoDate);
    }

    public static function unreadable(): self
    {
        return new self(DateCertainty::Unreadable);
    }

    /**
     * @param list<DateProblem> $problems
     */
    public static function certain(
        DateTimeImmutable $date,
        DatePrecision $precision,
        bool $yearKnown,
        array $problems,
        bool $centuryAssumed = false,
        bool $typed = false,
    ): self {
        return new self(DateCertainty::Certain, $date, $precision, $yearKnown, [], $problems, $centuryAssumed, $typed);
    }

    /**
     * A date written in numbers: one entry per order in which it is a real date. One order (or several that give the
     * same day) is a certain date, several are an open question.
     *
     * @param array<string, array{date: DateTimeImmutable, problems: list<DateProblem>}> $byOrder Keyed by DateOrder value
     */
    public static function inNumbers(array $byOrder, bool $yearKnown, bool $centuryAssumed): self
    {
        if ($byOrder === []) {
            return self::unreadable();
        }

        $orders = array_map(static fn (string $order): DateOrder => DateOrder::from($order), array_keys($byOrder));
        $days = array_unique(array_map(static fn (array $reading): string => $reading['date']->format('Y-m-d'), $byOrder));

        if (count($days) > 1) {
            return new self(DateCertainty::OrderOpen, null, DatePrecision::Day, $yearKnown, $orders, [], $centuryAssumed, byOrder: $byOrder);
        }

        $reading = $byOrder[array_key_first($byOrder)];

        return new self(DateCertainty::Certain, $reading['date'], DatePrecision::Day, $yearKnown, $orders, $reading['problems'], $centuryAssumed, byOrder: $byOrder);
    }

    /**
     * A short date - "Jan 23", "10/23": its reading as a day of the month (the year unknown) and as a month of a
     * two-digit year, each null (or unreadable) when the value is no date that way. With both it is an open question;
     * with one it is that reading, and says which it is so the column can settle the others.
     */
    public static function short(null|self $asDayOfMonth, null|self $asMonthOfYear): self
    {
        $day = $asDayOfMonth === null || $asDayOfMonth->isAReading() === false ? null : $asDayOfMonth;
        $month = $asMonthOfYear === null || $asMonthOfYear->isAReading() === false ? null : $asMonthOfYear;

        if ($day !== null && $month !== null) {
            return new self(
                DateCertainty::DayOrYearOpen,
                yearKnown: false,
                possibleShortMeanings: [ShortDateMeaning::DayOfMonth, ShortDateMeaning::MonthOfYear],
                asDayOfMonth: $day,
                asMonthOfYear: $month,
            );
        }

        $only = $day ?? $month;

        if ($only === null) {
            return self::unreadable();
        }

        return new self(
            $only->certainty,
            $only->date,
            $only->precision,
            $only->yearKnown,
            $only->possibleOrders,
            $only->problems,
            $only->centuryAssumed,
            false,
            [$day !== null ? ShortDateMeaning::DayOfMonth : ShortDateMeaning::MonthOfYear],
            $only->byOrder,
            $day,
            $month,
        );
    }

    /**
     * The reading of a short date under one meaning of its last number - null when it is no date that way. For any
     * other value: the value itself.
     */
    public function shortReading(ShortDateMeaning $meaning): null|self
    {
        if ($this->possibleShortMeanings === []) {
            return $this;
        }

        return $meaning === ShortDateMeaning::DayOfMonth ? $this->asDayOfMonth : $this->asMonthOfYear;
    }

    /**
     * This value once the column's short dates are settled: the reading under that meaning, unreadable when it has
     * none. A value that is no short date stays as it is.
     */
    public function asShort(ShortDateMeaning $meaning): self
    {
        return $this->shortReading($meaning) ?? self::unreadable();
    }

    /**
     * The date this value is in the given order: certain, or unreadable when it is no date that way. A value whose
     * order is not a question stays as it is.
     */
    public function inOrder(DateOrder $order): self
    {
        if ($this->possibleOrders === []) {
            return $this;
        }

        $reading = $this->byOrder[$order->value] ?? null;

        if ($reading === null) {
            return self::unreadable();
        }

        return new self(
            DateCertainty::Certain,
            $reading['date'],
            DatePrecision::Day,
            $this->yearKnown,
            [$order],
            $reading['problems'],
            $this->centuryAssumed,
            byOrder: [$order->value => $reading],
        );
    }

    public function allows(DateOrder $order): bool
    {
        return isset($this->byOrder[$order->value]);
    }

    /**
     * The day (Y-m-d) this value is in the given order - null when its order is not a question or it is no date so.
     */
    public function dayIn(DateOrder $order): null|string
    {
        return isset($this->byOrder[$order->value]) ? $this->byOrder[$order->value]['date']->format('Y-m-d') : null;
    }

    /**
     * @return list<DateProblem>
     */
    public function problemsIn(DateOrder $order): array
    {
        return $this->byOrder[$order->value]['problems'] ?? [];
    }

    private function isAReading(): bool
    {
        return $this->certainty === DateCertainty::Certain || $this->certainty === DateCertainty::OrderOpen;
    }
}
