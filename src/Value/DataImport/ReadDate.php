<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Value\DataImport;

use DateTimeImmutable;

/**
 * What ImportDateReader made of one cell of a date column (docs/features/data-import/value-readers.md "Dates").
 *
 * $date is midnight UTC of the day - of the first day of the month or year when only that much is known ($precision).
 * It is null while the value has no single reading: nothing, unreadable, or numbers that are a date in several orders
 * ($possibleOrders) - inOrder() gives the reading of one of them.
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
     *        value whose order is not a question (a typed date, a month's name, a four-digit year first)
     * @param list<DateProblem> $problems Of $date - empty while there is no single reading
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
        private array $byOrder = [],
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
    ): self {
        return new self(DateCertainty::Certain, $date, $precision, $yearKnown, [], $problems, $centuryAssumed);
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
            return new self(DateCertainty::OrderOpen, null, DatePrecision::Day, $yearKnown, $orders, [], $centuryAssumed, $byOrder);
        }

        $reading = $byOrder[array_key_first($byOrder)];

        return new self(DateCertainty::Certain, $reading['date'], DatePrecision::Day, $yearKnown, $orders, $reading['problems'], $centuryAssumed, $byOrder);
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
            [$order->value => $reading],
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
}
