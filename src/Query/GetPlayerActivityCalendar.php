<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Query;

use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Exceptions\PlayerNotFound;
use SpeedPuzzling\Web\Results\ActivityCalendarDay;

readonly final class GetPlayerActivityCalendar
{
    public function __construct(
        private Connection $database,
    ) {
    }

    /**
     * @return array<string, ActivityCalendarDay> Keyed by 'Y-m-d' string.
     * @throws PlayerNotFound
     */
    public function perDayInMonth(string $playerId, int $year, int $month): array
    {
        if (Uuid::isValid($playerId) === false) {
            throw new PlayerNotFound();
        }

        [$monthStart, $monthEnd] = $this->monthBounds($year, $month);

        $query = <<<SQL
SELECT
    to_char(finished_at, 'YYYY-MM-DD') AS day,
    puzzling_type,
    COUNT(*) AS solve_count,
    COUNT(*) FILTER (WHERE first_attempt = true) AS first_attempt_count,
    COALESCE(SUM(seconds_to_solve), 0) AS total_seconds
FROM puzzle_solving_time
WHERE
    finished_at IS NOT NULL
    AND finished_at >= :dateFrom
    AND finished_at <= :dateTo
    AND (
        player_id = :playerId
        OR (team::jsonb -> 'puzzlers') @> jsonb_build_array(jsonb_build_object('player_id', CAST(:playerId AS UUID)))
    )
GROUP BY day, puzzling_type
SQL;

        /** @var list<array{day: string, puzzling_type: string, solve_count: int|string, first_attempt_count: int|string, total_seconds: int|string}> $rows */
        $rows = $this->database->executeQuery($query, [
            'playerId' => $playerId,
            'dateFrom' => $monthStart->format('Y-m-d H:i:s'),
            'dateTo' => $monthEnd->format('Y-m-d H:i:s'),
        ])->fetchAllAssociative();

        /** @var array<string, array{solo: int, duo: int, team: int, firstAttempt: int, seconds: int}> $byDay */
        $byDay = [];

        foreach ($rows as $row) {
            $day = $row['day'];

            if (isset($byDay[$day]) === false) {
                $byDay[$day] = ['solo' => 0, 'duo' => 0, 'team' => 0, 'firstAttempt' => 0, 'seconds' => 0];
            }

            $count = (int) $row['solve_count'];
            $byDay[$day][$row['puzzling_type']] = ($byDay[$day][$row['puzzling_type']] ?? 0) + $count;
            $byDay[$day]['firstAttempt'] += (int) $row['first_attempt_count'];
            $byDay[$day]['seconds'] += (int) $row['total_seconds'];
        }

        $result = [];

        foreach ($byDay as $day => $counts) {
            $result[$day] = new ActivityCalendarDay(
                date: new DateTimeImmutable($day),
                soloCount: $counts['solo'],
                duoCount: $counts['duo'],
                teamCount: $counts['team'],
                firstAttemptCount: $counts['firstAttempt'],
                totalSeconds: $counts['seconds'],
            );
        }

        ksort($result);

        return $result;
    }

    /**
     * @return list<string> Sorted ascending list of unique 'Y-m-d' strings the player was active within the given month.
     * @throws PlayerNotFound
     */
    public function activeDaysInMonth(string $playerId, int $year, int $month): array
    {
        if (Uuid::isValid($playerId) === false) {
            throw new PlayerNotFound();
        }

        [$monthStart, $monthEnd] = $this->monthBounds($year, $month);

        $query = <<<SQL
SELECT DISTINCT to_char(finished_at, 'YYYY-MM-DD') AS day
FROM puzzle_solving_time
WHERE
    finished_at IS NOT NULL
    AND finished_at >= :dateFrom
    AND finished_at <= :dateTo
    AND (
        player_id = :playerId
        OR (team::jsonb -> 'puzzlers') @> jsonb_build_array(jsonb_build_object('player_id', CAST(:playerId AS UUID)))
    )
ORDER BY day ASC
SQL;

        /** @var list<array{day: string}> $rows */
        $rows = $this->database->executeQuery($query, [
            'playerId' => $playerId,
            'dateFrom' => $monthStart->format('Y-m-d H:i:s'),
            'dateTo' => $monthEnd->format('Y-m-d H:i:s'),
        ])->fetchAllAssociative();

        return array_map(static fn (array $row): string => $row['day'], $rows);
    }

    /**
     * @return array<int, int> Keys 0-6 (Mon=0 .. Sun=6), values = solve count within the given month. All 7 keys present.
     * @throws PlayerNotFound
     */
    public function dayOfWeekBucketsInMonth(string $playerId, int $year, int $month): array
    {
        if (Uuid::isValid($playerId) === false) {
            throw new PlayerNotFound();
        }

        [$monthStart, $monthEnd] = $this->monthBounds($year, $month);

        $query = <<<SQL
SELECT
    EXTRACT(ISODOW FROM finished_at)::int AS iso_dow,
    COUNT(*) AS solve_count
FROM puzzle_solving_time
WHERE
    finished_at IS NOT NULL
    AND finished_at >= :dateFrom
    AND finished_at <= :dateTo
    AND (
        player_id = :playerId
        OR (team::jsonb -> 'puzzlers') @> jsonb_build_array(jsonb_build_object('player_id', CAST(:playerId AS UUID)))
    )
GROUP BY iso_dow
SQL;

        /** @var list<array{iso_dow: int, solve_count: int|string}> $rows */
        $rows = $this->database->executeQuery($query, [
            'playerId' => $playerId,
            'dateFrom' => $monthStart->format('Y-m-d H:i:s'),
            'dateTo' => $monthEnd->format('Y-m-d H:i:s'),
        ])->fetchAllAssociative();

        $buckets = array_fill(0, 7, 0);

        foreach ($rows as $row) {
            // Postgres ISODOW returns 1 (Mon) – 7 (Sun); normalise to 0 (Mon) – 6 (Sun).
            $buckets[(int) $row['iso_dow'] - 1] = (int) $row['solve_count'];
        }

        return $buckets;
    }

    /**
     * @return array<int, int> Keys 0-23 (UTC hour of tracked_at), values = tracking count within the given month. All 24 keys present.
     * @throws PlayerNotFound
     */
    public function hourOfDayBucketsInMonth(string $playerId, int $year, int $month): array
    {
        if (Uuid::isValid($playerId) === false) {
            throw new PlayerNotFound();
        }

        [$monthStart, $monthEnd] = $this->monthBounds($year, $month);

        // `tracked_at` (auto-set at insert) is the honest "when did the user log this?" signal;
        // the hour portion of `finished_at` is unreliable because the UI only lets users pick a date.
        $query = <<<SQL
SELECT
    EXTRACT(HOUR FROM tracked_at)::int AS hour_of_day,
    COUNT(*) AS solve_count
FROM puzzle_solving_time
WHERE
    tracked_at >= :dateFrom
    AND tracked_at <= :dateTo
    AND (
        player_id = :playerId
        OR (team::jsonb -> 'puzzlers') @> jsonb_build_array(jsonb_build_object('player_id', CAST(:playerId AS UUID)))
    )
GROUP BY hour_of_day
SQL;

        /** @var list<array{hour_of_day: int, solve_count: int|string}> $rows */
        $rows = $this->database->executeQuery($query, [
            'playerId' => $playerId,
            'dateFrom' => $monthStart->format('Y-m-d H:i:s'),
            'dateTo' => $monthEnd->format('Y-m-d H:i:s'),
        ])->fetchAllAssociative();

        $buckets = array_fill(0, 24, 0);

        foreach ($rows as $row) {
            $buckets[(int) $row['hour_of_day']] = (int) $row['solve_count'];
        }

        return $buckets;
    }

    /**
     * @return array{0: DateTimeImmutable, 1: DateTimeImmutable}
     */
    private function monthBounds(int $year, int $month): array
    {
        $start = new DateTimeImmutable(sprintf('%04d-%02d-01 00:00:00', $year, $month));
        $end = $start->modify('last day of this month')->setTime(23, 59, 59);

        return [$start, $end];
    }
}
