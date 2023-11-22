<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Results;

use DateTimeImmutable;
use DateTimeInterface;
use SpeedPuzzling\Web\Value\StopwatchStatus;

readonly final class StopwatchDetail
{
    public string $hoursElapsed;
    public string $minutesElapsed;
    public string $secondsElapsed;

    public function __construct(
        public string $stopwatchId,
        public int $totalSeconds,
        public DateTimeInterface $lastStart,
        public null|DateTimeInterface $lastEnd,
        public StopwatchStatus $status,
    ) {
        $interval = $totalSeconds;

        if ($lastEnd === null) {
            $interval += (new \DateTimeImmutable())->getTimestamp() - $lastStart->getTimestamp();
        }

        $this->hoursElapsed = (string) floor($interval / 3600);
        $this->minutesElapsed = str_pad((string) floor(($interval / 60) % 60), 2, '0', STR_PAD_LEFT);
        $this->secondsElapsed = str_pad((string) ($interval % 60), 2, '0', STR_PAD_LEFT);
    }

    /**
     * @param array{
     *     stopwatch_id: string,
     *     total_seconds: null|int|float|string,
     *     last_start_time: null|string,
     *     last_end_time: null|string,
     *     status: string,
     * } $row
     */
    public static function fromDatabaseRow(array $row): self
    {
        $lastStart = new DateTimeImmutable();
        if ($row['last_start_time'] !== null) {
            $lastStart = DateTimeImmutable::createFromFormat('Y-m-d H:i:s', $row['last_start_time']);
            assert($lastStart instanceof DateTimeImmutable);
        }

        $lastEnd = null;
        if ($row['last_end_time'] !== null) {
            $lastEnd = DateTimeImmutable::createFromFormat('Y-m-d H:i:s', $row['last_end_time']);
            assert($lastEnd instanceof DateTimeImmutable);
        }

        $totalSeconds = 0;
        if ($row['total_seconds'] !== null) {
            $totalSeconds = (int) $row['total_seconds'];
        }

        return new self(
            stopwatchId: $row['stopwatch_id'],
            totalSeconds: $totalSeconds,
            lastStart: $lastStart,
            lastEnd: $lastEnd,
            status: StopwatchStatus::from($row['status'])
        );
    }
}