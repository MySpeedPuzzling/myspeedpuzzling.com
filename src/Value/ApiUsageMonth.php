<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Value;

use DateTimeImmutable;
use DateTimeZone;

/**
 * The month an API usage page shows - UTC, like the counters. `?month=2026-10`;
 * anything else, a future month or one beyond the 24-month retention falls back
 * to the current month.
 */
final readonly class ApiUsageMonth
{
    public const int RETENTION_MONTHS = 24;

    private function __construct(
        // First day of the month, 00:00 UTC
        public DateTimeImmutable $start,
        private DateTimeImmutable $now,
    ) {
    }

    public static function fromUserInput(mixed $input, DateTimeImmutable $now): self
    {
        $utcNow = $now->setTimezone(new DateTimeZone('UTC'));
        $current = $utcNow->modify('first day of this month')->setTime(0, 0);

        if (!is_string($input) || preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $input) !== 1) {
            return new self($current, $utcNow);
        }

        $start = DateTimeImmutable::createFromFormat('!Y-m-d', $input . '-01', new DateTimeZone('UTC'));
        $oldest = $current->modify('-' . self::RETENTION_MONTHS . ' months');

        if ($start === false || $start > $current || $start < $oldest) {
            return new self($current, $utcNow);
        }

        return new self($start, $utcNow);
    }

    /**
     * Exclusive end: the first day of the next month
     */
    public function end(): DateTimeImmutable
    {
        return $this->start->modify('first day of next month');
    }

    public function value(): string
    {
        return $this->start->format('Y-m');
    }

    public function previous(): null|self
    {
        $previous = $this->start->modify('first day of previous month');
        $oldest = $this->now->modify('first day of this month')->setTime(0, 0)->modify('-' . self::RETENTION_MONTHS . ' months');

        return $previous < $oldest ? null : new self($previous, $this->now);
    }

    public function next(): null|self
    {
        $next = $this->end();

        return $next > $this->now ? null : new self($next, $this->now);
    }

    /**
     * Every day of the month up to today, as Y-m-d
     *
     * @return list<string>
     */
    public function days(): array
    {
        $days = [];
        $today = $this->now->format('Y-m-d');

        for ($day = $this->start; $day < $this->end(); $day = $day->modify('+1 day')) {
            $days[] = $day->format('Y-m-d');

            if ($day->format('Y-m-d') === $today) {
                break;
            }
        }

        return $days;
    }
}
