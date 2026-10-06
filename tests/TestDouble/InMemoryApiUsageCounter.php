<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\TestDouble;

use DateTimeImmutable;
use DateTimeZone;
use SpeedPuzzling\Web\Services\ApiUsage\ApiUsageCounter;
use SpeedPuzzling\Web\Value\ApiCallerActivity;
use SpeedPuzzling\Web\Value\ApiRequestRecord;
use SpeedPuzzling\Web\Value\ApiUsageCount;
use SpeedPuzzling\Web\Value\ApiUsageSnapshot;

/**
 * The counters without Redis, for the test environment (CI's functional tests run
 * without a Redis service). Same semantics as RedisApiUsageCounter, whose Lua
 * script is tested against a real Redis in RedisApiUsageCounterTest.
 *
 * Deliberately not reset between requests of one kernel: a test making two
 * requests reads both records and proves nothing leaked from one to the other.
 */
final class InMemoryApiUsageCounter implements ApiUsageCounter
{
    /** @var list<ApiRequestRecord> */
    private array $records = [];

    public function record(ApiRequestRecord $record): void
    {
        $this->records[] = $record;
    }

    /**
     * @return list<ApiRequestRecord>
     */
    public function records(): array
    {
        return $this->records;
    }

    public function snapshot(DateTimeImmutable $day): ApiUsageSnapshot
    {
        $utcDay = $day->setTimezone(new DateTimeZone('UTC'))->setTime(0, 0);

        /** @var array<string, ApiUsageCount> $counts */
        $counts = [];
        /** @var array<string, array<string, int>> $perMinute */
        $perMinute = [];
        /** @var array<string, ApiRequestRecord> $latest */
        $latest = [];

        foreach ($this->records as $record) {
            $at = $record->at->setTimezone(new DateTimeZone('UTC'));

            if ($at->format('Ymd') !== $utcDay->format('Ymd')) {
                continue;
            }

            $callerKey = $record->caller->key();
            $field = $callerKey . '|' . $record->operation . '|' . $record->statusClass->value;
            $previous = $counts[$field] ?? null;

            $counts[$field] = new ApiUsageCount(
                caller: $record->caller,
                operation: $record->operation,
                statusClass: $record->statusClass,
                requests: ($previous->requests ?? 0) + 1,
                durationMsTotal: ($previous->durationMsTotal ?? 0) + max(0, $record->durationMs),
            );

            $minute = $at->format('YmdHi');
            $perMinute[$callerKey][$minute] = ($perMinute[$callerKey][$minute] ?? 0) + 1;

            if (!isset($latest[$callerKey]) || $latest[$callerKey]->at < $record->at) {
                $latest[$callerKey] = $record;
            }
        }

        $callers = [];

        foreach ($latest as $callerKey => $record) {
            $callers[] = new ApiCallerActivity(
                caller: $record->caller,
                peakRequestsPerMinute: max([0, ...$perMinute[$callerKey]]),
                lastRequestAt: new DateTimeImmutable('@' . $record->at->getTimestamp()),
            );
        }

        return new ApiUsageSnapshot($utcDay, array_values($counts), $callers);
    }
}
