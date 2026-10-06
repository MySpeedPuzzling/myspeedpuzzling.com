<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Query;

use DateTimeImmutable;
use DateTimeZone;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use SpeedPuzzling\Web\Results\ApiUsageOperationRow;
use SpeedPuzzling\Web\Results\ApiUsageRecentTotals;
use SpeedPuzzling\Web\Results\ApiUsageTotals;
use SpeedPuzzling\Web\Value\ApiCallerKind;
use SpeedPuzzling\Web\Value\ApiStatusClass;
use SpeedPuzzling\Web\Value\ApiUsageFilter;
use SpeedPuzzling\Web\Value\ApiUsageMonth;

/**
 * Aggregates of the stored API usage (docs/features/api/usage-statistics.md) -
 * numbers only, never who the callers are (GetApiUsageCallers does that, for admins).
 * Data up to the last 5-minute copy from the counters.
 */
readonly final class GetApiUsage
{
    public function __construct(
        private Connection $database,
    ) {
    }

    public function totals(ApiUsageFilter $filter, ApiUsageMonth $month): ApiUsageTotals
    {
        $parameters = self::monthParameters($month);
        $where = self::where($filter, 'u', true, $parameters);

        /** @var array{requests: null|int|string, failed: null|int|string, too_many: null|int|string, duration: null|int|string} $usage */
        $usage = $this->database->executeQuery(
            <<<SQL
SELECT
    SUM(u.requests) AS requests,
    SUM(u.requests) FILTER (WHERE u.status_class IN (:failedClasses)) AS failed,
    SUM(u.requests) FILTER (WHERE u.status_class = :tooManyClass) AS too_many,
    SUM(u.duration_ms_total) AS duration
FROM api_usage_day u
WHERE u.day >= :from AND u.day < :to {$where}
SQL,
            $parameters + self::statusParameters(),
            self::types(),
        )->fetchAssociative() ?: ['requests' => null, 'failed' => null, 'too_many' => null, 'duration' => null];

        $peak = null;
        $lastRequestAt = null;

        if ($filter->operation === null) {
            $callerParameters = self::monthParameters($month);
            $callerWhere = self::where($filter, 'c', false, $callerParameters);

            /** @var array{peak: null|int|string, last_request_at: null|string} $callers */
            $callers = $this->database->executeQuery(
                <<<SQL
SELECT MAX(c.peak_requests_per_minute) AS peak, MAX(c.last_request_at) AS last_request_at
FROM api_caller_day c
WHERE c.day >= :from AND c.day < :to {$callerWhere}
SQL,
                $callerParameters,
            )->fetchAssociative() ?: ['peak' => null, 'last_request_at' => null];

            $peak = $callers['peak'] !== null ? (int) $callers['peak'] : null;
            $lastRequestAt = $callers['last_request_at'] !== null ? new DateTimeImmutable($callers['last_request_at']) : null;
        }

        return new ApiUsageTotals(
            requests: (int) $usage['requests'],
            failed: (int) $usage['failed'],
            tooManyRequests: (int) $usage['too_many'],
            durationMsTotal: (int) $usage['duration'],
            peakRequestsPerMinute: $peak,
            lastRequestAt: $lastRequestAt,
        );
    }

    /**
     * Requests per day, split by status class or by caller key
     *
     * @param 'status_class'|'caller_key' $groupBy
     * @return array<string, array<string, int>> group => [Y-m-d => requests]
     */
    public function daily(ApiUsageFilter $filter, ApiUsageMonth $month, string $groupBy): array
    {
        $parameters = self::monthParameters($month);
        $where = self::where($filter, 'u', true, $parameters);
        $groupColumn = $groupBy === 'caller_key' ? 'u.caller_key' : 'u.status_class';

        /** @var list<array{day: string, grp: string, requests: int|string}> $rows */
        $rows = $this->database->executeQuery(
            <<<SQL
SELECT u.day, {$groupColumn} AS grp, SUM(u.requests) AS requests
FROM api_usage_day u
WHERE u.day >= :from AND u.day < :to {$where}
GROUP BY u.day, {$groupColumn}
ORDER BY u.day
SQL,
            $parameters,
        )->fetchAllAssociative();

        $daily = [];

        foreach ($rows as $row) {
            $daily[$row['grp']][$row['day']] = (int) $row['requests'];
        }

        return $daily;
    }

    /**
     * @return list<ApiUsageOperationRow> busiest first
     */
    public function byOperation(ApiUsageFilter $filter, ApiUsageMonth $month): array
    {
        $parameters = self::monthParameters($month);
        $where = self::where($filter, 'u', true, $parameters);

        /** @var list<array{operation: string, requests: int|string, failed: null|int|string, too_many: null|int|string, duration: int|string, callers: int|string}> $rows */
        $rows = $this->database->executeQuery(
            <<<SQL
SELECT
    u.operation,
    SUM(u.requests) AS requests,
    SUM(u.requests) FILTER (WHERE u.status_class IN (:failedClasses)) AS failed,
    SUM(u.requests) FILTER (WHERE u.status_class = :tooManyClass) AS too_many,
    SUM(u.duration_ms_total) AS duration,
    COUNT(DISTINCT u.caller_key) AS callers
FROM api_usage_day u
WHERE u.day >= :from AND u.day < :to {$where}
GROUP BY u.operation
ORDER BY requests DESC, u.operation
SQL,
            $parameters + self::statusParameters(),
            self::types(),
        )->fetchAllAssociative();

        return array_map(
            static fn (array $row): ApiUsageOperationRow => new ApiUsageOperationRow(
                operation: $row['operation'],
                requests: (int) $row['requests'],
                failed: (int) $row['failed'],
                tooManyRequests: (int) $row['too_many'],
                durationMsTotal: (int) $row['duration'],
                callers: (int) $row['callers'],
            ),
            $rows,
        );
    }

    /**
     * @return array<string, int> status class => requests, in ApiStatusClass order
     */
    public function byStatusClass(ApiUsageFilter $filter, ApiUsageMonth $month): array
    {
        $parameters = self::monthParameters($month);
        $where = self::where($filter, 'u', true, $parameters);

        /** @var array<string, int|string> $rows */
        $rows = $this->database->executeQuery(
            <<<SQL
SELECT u.status_class, SUM(u.requests) AS requests
FROM api_usage_day u
WHERE u.day >= :from AND u.day < :to {$where}
GROUP BY u.status_class
SQL,
            $parameters,
        )->fetchAllKeyValue();

        $byStatus = [];

        foreach (ApiStatusClass::cases() as $statusClass) {
            if (isset($rows[$statusClass->value])) {
                $byStatus[$statusClass->value] = (int) $rows[$statusClass->value];
            }
        }

        return $byStatus;
    }

    /**
     * @return array<string, int> caller key => requests
     */
    public function requestsByCaller(ApiUsageFilter $filter, ApiUsageMonth $month): array
    {
        $parameters = self::monthParameters($month);
        $where = self::where($filter, 'u', true, $parameters);

        /** @var array<string, int|string> $rows */
        $rows = $this->database->executeQuery(
            <<<SQL
SELECT u.caller_key, SUM(u.requests) AS requests
FROM api_usage_day u
WHERE u.day >= :from AND u.day < :to {$where}
GROUP BY u.caller_key
SQL,
            $parameters,
        )->fetchAllKeyValue();

        return array_map('intval', $rows);
    }

    /**
     * Last 30 days of everything the edit-profile page lists - one query for the page.
     *
     * @param list<string> $ownClientIdentifiers the player's approved apps
     */
    public function recentForPlayer(string $playerId, array $ownClientIdentifiers, DateTimeImmutable $now): ApiUsageRecentTotals
    {
        $since = $now->setTimezone(new DateTimeZone('UTC'))
            ->modify('-' . (ApiUsageRecentTotals::DAYS - 1) . ' days')
            ->format('Y-m-d');

        /** @var list<array{kind: string, id: string, requests: int|string}> $rows */
        $rows = $this->database->executeQuery(
            <<<SQL
SELECT 'pat' AS kind, u.personal_access_token_id::text AS id, SUM(u.requests) AS requests
FROM api_usage_day u
WHERE u.player_id = :playerId AND u.personal_access_token_id IS NOT NULL AND u.day >= :since
GROUP BY u.personal_access_token_id
UNION ALL
SELECT 'connected', u.oauth2_client_identifier, SUM(u.requests)
FROM api_usage_day u
WHERE u.player_id = :playerId AND u.caller_key LIKE :oauthPrefix AND u.day >= :since
GROUP BY u.oauth2_client_identifier
UNION ALL
SELECT 'own', u.oauth2_client_identifier, SUM(u.requests)
FROM api_usage_day u
WHERE u.oauth2_client_identifier IN (:ownClients) AND u.day >= :since
GROUP BY u.oauth2_client_identifier
SQL,
            [
                'playerId' => $playerId,
                'since' => $since,
                'oauthPrefix' => ApiCallerKind::OAuth2User->value . ':%',
                // IN () with an empty list is invalid SQL; no client has an empty identifier
                'ownClients' => $ownClientIdentifiers === [] ? [''] : $ownClientIdentifiers,
            ],
            ['ownClients' => ArrayParameterType::STRING],
        )->fetchAllAssociative();

        $totals = ['pat' => [], 'connected' => [], 'own' => []];

        foreach ($rows as $row) {
            $totals[$row['kind']][$row['id']] = (int) $row['requests'];
        }

        return new ApiUsageRecentTotals($totals['pat'], $totals['connected'], $totals['own']);
    }

    /**
     * @return array{from: string, to: string}
     */
    private static function monthParameters(ApiUsageMonth $month): array
    {
        return [
            'from' => $month->start->format('Y-m-d'),
            'to' => $month->end()->format('Y-m-d'),
        ];
    }

    /**
     * @return array{failedClasses: list<string>, tooManyClass: string}
     */
    private static function statusParameters(): array
    {
        return [
            'failedClasses' => array_values(array_map(
                static fn (ApiStatusClass $statusClass): string => $statusClass->value,
                array_filter(ApiStatusClass::cases(), static fn (ApiStatusClass $statusClass): bool => $statusClass->isFailure()),
            )),
            'tooManyClass' => ApiStatusClass::TooManyRequests->value,
        ];
    }

    /**
     * @return array<string, ArrayParameterType>
     */
    private static function types(): array
    {
        return ['failedClasses' => ArrayParameterType::STRING];
    }

    /**
     * The filter as "AND …" conditions on the alias; shared with GetApiUsageCallers.
     *
     * @param array<string, mixed> $parameters
     * @param-out array<string, mixed> $parameters
     */
    public static function where(ApiUsageFilter $filter, string $alias, bool $usageTable, array &$parameters): string
    {
        $conditions = [];

        if ($filter->playerId !== null) {
            $conditions[] = "{$alias}.player_id = :filterPlayerId";
            $parameters['filterPlayerId'] = $filter->playerId;
        }

        if ($filter->personalAccessTokenId !== null) {
            $conditions[] = "{$alias}.personal_access_token_id = :filterTokenId";
            $parameters['filterTokenId'] = $filter->personalAccessTokenId;
        }

        if ($filter->oauth2ClientIdentifier !== null) {
            $conditions[] = "{$alias}.oauth2_client_identifier = :filterClientId";
            $parameters['filterClientId'] = $filter->oauth2ClientIdentifier;
        }

        if ($filter->callerKind !== null) {
            $conditions[] = "{$alias}.caller_key LIKE :filterKindPrefix";
            $parameters['filterKindPrefix'] = $filter->callerKind->value . ':%';
        }

        if ($filter->callerKey !== null) {
            $conditions[] = "{$alias}.caller_key = :filterCallerKey";
            $parameters['filterCallerKey'] = $filter->callerKey;
        }

        if ($usageTable && $filter->operation !== null) {
            $conditions[] = "{$alias}.operation = :filterOperation";
            $parameters['filterOperation'] = $filter->operation;
        }

        return $conditions === [] ? '' : 'AND ' . implode(' AND ', $conditions);
    }
}
