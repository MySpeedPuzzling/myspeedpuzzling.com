<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Query;

use DateTimeImmutable;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use SpeedPuzzling\Web\Results\DuplicateCaseListItem;
use SpeedPuzzling\Web\Results\DuplicateResultsTotals;
use SpeedPuzzling\Web\Value\DuplicateCaseListTab;
use SpeedPuzzling\Web\Value\DuplicateCaseStatus;
use SpeedPuzzling\Web\Value\DuplicateKind;
use SpeedPuzzling\Web\Value\DuplicateTier;

/**
 * Admin numbers of duplicate results (docs/features/duplicate-results.md, "Admin overview").
 * Reads only the stored cases - the live scan of all results runs in the detection cron.
 *
 * A case is per person, so a teammate copy is two cases; the twin counts (trend, gap classes) count pairs of
 * results instead.
 */
readonly final class GetDuplicateResultsOverview
{
    public const int PER_PAGE = 50;

    public function __construct(
        private Connection $database,
    ) {
    }

    public function totals(DateTimeImmutable $now): DuplicateResultsTotals
    {
        $query = <<<SQL
SELECT
    COUNT(*) FILTER (WHERE status = :open) AS open_cases,
    COUNT(DISTINCT player_id) FILTER (WHERE status = :open) AS players_affected,
    COUNT(*) FILTER (WHERE status IN (:resolved) AND resolved_at > :monthAgo) AS resolved_last_30_days,
    COUNT(*) FILTER (WHERE status IN (:confirmedReal)) AS confirmed_real
FROM result_duplicate_case
SQL;

        /** @var array{open_cases: int|string, players_affected: int|string, resolved_last_30_days: int|string, confirmed_real: int|string} $row */
        $row = $this->database->fetchAssociative($query, [
            'open' => DuplicateCaseStatus::Open->value,
            'resolved' => self::values(DuplicateCaseStatus::resolved()),
            'confirmedReal' => self::values(DuplicateCaseStatus::confirmedReal()),
            'monthAgo' => $now->modify('-30 days')->format('Y-m-d H:i:s'),
        ], [
            'resolved' => ArrayParameterType::STRING,
            'confirmedReal' => ArrayParameterType::STRING,
        ]);

        return new DuplicateResultsTotals(
            openCases: (int) $row['open_cases'],
            playersAffected: (int) $row['players_affected'],
            resolvedLast30Days: (int) $row['resolved_last_30_days'],
            confirmedReal: (int) $row['confirmed_real'],
        );
    }

    /**
     * @return array<string, array<string, int>> tier => status => cases
     */
    public function casesByTierAndStatus(): array
    {
        return $this->matrix('tier');
    }

    /**
     * @return array<string, array<string, int>> kind => status => cases
     */
    public function casesByKindAndStatus(): array
    {
        return $this->matrix('kind');
    }

    /**
     * New twins per month, by when the later copy was saved - the success measure of the prevention layers.
     *
     * @return list<array{month: string, twins: int, saved_within_two_minutes: int}> oldest first, the last 12 months
     */
    public function monthlyTrend(DateTimeImmutable $now): array
    {
        $query = <<<SQL
WITH pair AS (
    SELECT DISTINCT ON (time_a_id, time_b_id)
        CAST(snapshot->>'tracked_at_b' AS timestamp) AS saved_at,
        CAST(snapshot->>'gap_seconds' AS integer) AS gap_seconds
    FROM result_duplicate_case
)
SELECT
    to_char(date_trunc('month', saved_at), 'YYYY-MM') AS month,
    COUNT(*) AS twins,
    COUNT(*) FILTER (WHERE gap_seconds <= 120) AS saved_within_two_minutes
FROM pair
WHERE saved_at >= :since
GROUP BY 1
ORDER BY 1
SQL;

        /** @var list<array{month: string, twins: int|string, saved_within_two_minutes: int|string}> $rows */
        $rows = $this->database->fetchAllAssociative($query, [
            'since' => $now->modify('first day of this month')->modify('-11 months')->format('Y-m-d 00:00:00'),
        ]);

        return array_map(static fn (array $row): array => [
            'month' => $row['month'],
            'twins' => (int) $row['twins'],
            'saved_within_two_minutes' => (int) $row['saved_within_two_minutes'],
        ], $rows);
    }

    /**
     * How long after the first copy the second one was saved, by pair of results.
     *
     * @return array<string, int> label => twins, every class present, shortest first
     */
    public function gapClasses(): array
    {
        $query = <<<SQL
WITH pair AS (
    SELECT DISTINCT ON (time_a_id, time_b_id) CAST(snapshot->>'gap_seconds' AS integer) AS gap_seconds
    FROM result_duplicate_case
)
SELECT
    COUNT(*) FILTER (WHERE gap_seconds <= 10) AS up_to_10_seconds,
    COUNT(*) FILTER (WHERE gap_seconds > 10 AND gap_seconds <= 60) AS up_to_1_minute,
    COUNT(*) FILTER (WHERE gap_seconds > 60 AND gap_seconds <= 600) AS up_to_10_minutes,
    COUNT(*) FILTER (WHERE gap_seconds > 600 AND gap_seconds <= 3600) AS up_to_1_hour,
    COUNT(*) FILTER (WHERE gap_seconds > 3600 AND gap_seconds <= 86400) AS up_to_1_day,
    COUNT(*) FILTER (WHERE gap_seconds > 86400) AS more_than_a_day
FROM pair
SQL;

        /** @var array<string, int|string> $row */
        $row = $this->database->fetchAssociative($query);

        return [
            '≤ 10 s' => (int) $row['up_to_10_seconds'],
            '11–60 s' => (int) $row['up_to_1_minute'],
            '1–10 min' => (int) $row['up_to_10_minutes'],
            '10–60 min' => (int) $row['up_to_1_hour'],
            '1–24 h' => (int) $row['up_to_1_day'],
            '> 1 day' => (int) $row['more_than_a_day'],
        ];
    }

    /**
     * The real precision of each tier: of the decided cases, how many the players said were two solves.
     *
     * @return array<string, array{decided: int, confirmed_real: int, percent: null|int}> tier => numbers, every tier present
     */
    public function confirmedRealShareByTier(): array
    {
        $query = <<<SQL
SELECT
    tier,
    COUNT(*) FILTER (WHERE status IN (:resolved) OR status IN (:confirmedReal)) AS decided,
    COUNT(*) FILTER (WHERE status IN (:confirmedReal)) AS confirmed_real
FROM result_duplicate_case
GROUP BY tier
SQL;

        /** @var list<array{tier: string, decided: int|string, confirmed_real: int|string}> $rows */
        $rows = $this->database->fetchAllAssociative($query, [
            'resolved' => self::values(DuplicateCaseStatus::resolved()),
            'confirmedReal' => self::values(DuplicateCaseStatus::confirmedReal()),
        ], [
            'resolved' => ArrayParameterType::STRING,
            'confirmedReal' => ArrayParameterType::STRING,
        ]);

        $shares = [];

        foreach (DuplicateTier::cases() as $tier) {
            $shares[$tier->value] = ['decided' => 0, 'confirmed_real' => 0, 'percent' => null];
        }

        foreach ($rows as $row) {
            $decided = (int) $row['decided'];
            $confirmedReal = (int) $row['confirmed_real'];

            $shares[$row['tier']] = [
                'decided' => $decided,
                'confirmed_real' => $confirmedReal,
                'percent' => $decided > 0 ? (int) round($confirmedReal / $decided * 100) : null,
            ];
        }

        return $shares;
    }

    public function countCases(DuplicateCaseListTab $tab, null|DuplicateTier $tier, null|DuplicateKind $kind): int
    {
        [$where, $parameters, $types] = $this->listFilter($tab, $tier, $kind);

        $count = $this->database->fetchOne("SELECT COUNT(*) FROM result_duplicate_case c WHERE {$where}", $parameters, $types);

        return is_numeric($count) ? (int) $count : 0;
    }

    /**
     * @return list<DuplicateCaseListItem> newest detection first
     */
    public function cases(DuplicateCaseListTab $tab, null|DuplicateTier $tier, null|DuplicateKind $kind, int $page): array
    {
        [$where, $parameters, $types] = $this->listFilter($tab, $tier, $kind);

        $query = <<<SQL
SELECT
    c.id,
    c.player_id,
    player.name AS player_name,
    player.code AS player_code,
    c.tier,
    c.kind,
    c.status,
    c.detected_at,
    c.resolved_at,
    c.snapshot
FROM result_duplicate_case c
INNER JOIN player ON player.id = c.player_id
WHERE {$where}
ORDER BY c.detected_at DESC, CAST(c.snapshot->>'tracked_at_b' AS timestamp) DESC, c.id
LIMIT :limit OFFSET :offset
SQL;

        $parameters['limit'] = self::PER_PAGE;
        $parameters['offset'] = (max(1, $page) - 1) * self::PER_PAGE;

        /** @var list<array{id: string, player_id: string, player_name: null|string, player_code: string, tier: string, kind: string, status: string, detected_at: string, resolved_at: null|string, snapshot: string}> $rows */
        $rows = $this->database->fetchAllAssociative($query, $parameters, $types);

        return array_map(static function (array $row): DuplicateCaseListItem {
            /** @var array{puzzle_id: string, puzzle_name: string, seconds: int, day_a: string, day_b: string, tracked_at_a: string, tracked_at_b: string, gap_seconds: int, differences: list<string>, tracker_a: array{id: string, name: null|string, code: string}, tracker_b: array{id: string, name: null|string, code: string}, others: list<array{id: string, name: null|string, code: string}>} $snapshot */
            $snapshot = json_decode($row['snapshot'], true, flags: JSON_THROW_ON_ERROR);

            return new DuplicateCaseListItem(
                caseId: $row['id'],
                playerId: $row['player_id'],
                playerName: $row['player_name'],
                playerCode: $row['player_code'],
                puzzleId: $snapshot['puzzle_id'],
                puzzleName: $snapshot['puzzle_name'],
                seconds: $snapshot['seconds'],
                dayA: $snapshot['day_a'],
                dayB: $snapshot['day_b'],
                trackedAtA: new DateTimeImmutable($snapshot['tracked_at_a']),
                trackedAtB: new DateTimeImmutable($snapshot['tracked_at_b']),
                gapSeconds: $snapshot['gap_seconds'],
                differences: $snapshot['differences'],
                trackerA: $snapshot['tracker_a'],
                trackerB: $snapshot['tracker_b'],
                others: $snapshot['others'],
                tier: DuplicateTier::from($row['tier']),
                kind: DuplicateKind::from($row['kind']),
                status: DuplicateCaseStatus::from($row['status']),
                detectedAt: new DateTimeImmutable($row['detected_at']),
                resolvedAt: $row['resolved_at'] !== null ? new DateTimeImmutable($row['resolved_at']) : null,
            );
        }, $rows);
    }

    /**
     * @return array<string, array<string, int>>
     */
    private function matrix(string $column): array
    {
        /** @var list<array{group_value: string, status: string, cases: int|string}> $rows */
        $rows = $this->database->fetchAllAssociative(
            "SELECT {$column} AS group_value, status, COUNT(*) AS cases FROM result_duplicate_case GROUP BY 1, 2",
        );

        $matrix = [];

        foreach ($rows as $row) {
            $matrix[$row['group_value']][$row['status']] = (int) $row['cases'];
        }

        return $matrix;
    }

    /**
     * @return array{string, array<string, mixed>, array<string, ArrayParameterType>}
     */
    private function listFilter(DuplicateCaseListTab $tab, null|DuplicateTier $tier, null|DuplicateKind $kind): array
    {
        $where = 'c.status IN (:statuses)';
        $parameters = ['statuses' => self::values($tab->statuses())];
        $types = ['statuses' => ArrayParameterType::STRING];

        if ($tier !== null) {
            $where .= ' AND c.tier = :tier';
            $parameters['tier'] = $tier->value;
        }

        if ($kind !== null) {
            $where .= ' AND c.kind = :kind';
            $parameters['kind'] = $kind->value;
        }

        return [$where, $parameters, $types];
    }

    /**
     * @param list<DuplicateCaseStatus> $statuses
     * @return list<string>
     */
    private static function values(array $statuses): array
    {
        return array_map(static fn (DuplicateCaseStatus $status): string => $status->value, $statuses);
    }
}
