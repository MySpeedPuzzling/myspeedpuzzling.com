<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Results;

use DateTimeImmutable;
use DateTimeInterface;
use SpeedPuzzling\Web\Value\StopwatchStatus;

readonly final class StopwatchDetail
{
    public int $interval;

    public function __construct(
        public string $stopwatchId,
        public int $totalSeconds,
        public DateTimeInterface $lastStart,
        public null|DateTimeInterface $lastEnd,
        public StopwatchStatus $status,
        public null|string $puzzleId,
        public null|string $puzzleName,
        public null|string $name,
        // The clock's now, from the query - a result reads no clock; a running lap counts up to it
        DateTimeInterface $now,
        // Finished laps to the millisecond, so the stopwatch page goes on from exactly what it showed
        public int $totalMilliseconds = 0,
    ) {
        $interval = $totalSeconds;

        if ($lastEnd === null) {
            $interval += $now->getTimestamp() - $lastStart->getTimestamp();
        }

        $this->interval = $interval;
    }

    /**
     * @param array{
     *     stopwatch_id: string,
     *     total_seconds: null|int|float|string,
     *     last_start_time: null|string,
     *     last_end_time: null|string,
     *     status: string,
     *     puzzle_id: null|string,
     *     puzzle_name: null|string,
     *     stopwatch_name: null|string,
     * } $row
     */
    public static function fromDatabaseRow(array $row, DateTimeImmutable $now): self
    {
        $lastStart = $now;
        if ($row['last_start_time'] !== null) {
            // Postgres prints the milliseconds of a lap (stored since 2026-10-12) only when there are some
            $lastStart = new DateTimeImmutable($row['last_start_time']);
        }

        $lastEnd = null;
        if ($row['last_end_time'] !== null) {
            $lastEnd = new DateTimeImmutable($row['last_end_time']);
        }

        $totalMilliseconds = 0;
        if ($row['total_seconds'] !== null) {
            $totalMilliseconds = (int) round((float) $row['total_seconds'] * 1000);
        }

        return new self(
            stopwatchId: $row['stopwatch_id'],
            // Whole seconds cut off, never rounded up - the stopwatch shows them that way
            totalSeconds: intdiv($totalMilliseconds, 1000),
            lastStart: $lastStart,
            lastEnd: $lastEnd,
            status: StopwatchStatus::from($row['status']),
            puzzleId: $row['puzzle_id'],
            puzzleName: $row['puzzle_name'],
            name: $row['stopwatch_name'],
            now: $now,
            totalMilliseconds: $totalMilliseconds,
        );
    }
}
