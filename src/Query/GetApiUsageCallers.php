<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Query;

use DateTimeImmutable;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use InvalidArgumentException;
use SpeedPuzzling\Web\Results\ApiUsageCallerRow;
use SpeedPuzzling\Web\Value\ApiCaller;
use SpeedPuzzling\Web\Value\ApiUsageFilter;
use SpeedPuzzling\Web\Value\ApiUsageMonth;

/**
 * Every API caller of a period with the names behind it - the admin overview
 * (/admin/api-usage). Players see only their own callers, which the usage page
 * names from their own token and app lists, never through this query.
 */
readonly final class GetApiUsageCallers
{
    public function __construct(
        private Connection $database,
    ) {
    }

    /**
     * @return list<ApiUsageCallerRow> busiest first
     */
    public function forMonth(ApiUsageFilter $filter, ApiUsageMonth $month): array
    {
        $parameters = [
            'from' => $month->start->format('Y-m-d'),
            'to' => $month->end()->format('Y-m-d'),
            'failedClasses' => ['4xx', '429', '5xx'],
        ];
        $where = GetApiUsage::where($filter, 'u', true, $parameters);

        /**
         * @var list<array{
         *     caller_key: string,
         *     requests: int|string,
         *     failed: null|int|string,
         *     too_many: null|int|string,
         *     duration: int|string,
         *     peak: null|int|string,
         *     last_request_at: null|string,
         *     player_name: null|string,
         *     player_code: null|string,
         *     token_name: null|string,
         *     client_name: null|string,
         * }> $rows
         */
        $rows = $this->database->executeQuery(
            <<<SQL
WITH usage AS (
    SELECT
        u.caller_key,
        MIN(u.player_id::text)::uuid AS player_id,
        MIN(u.personal_access_token_id::text)::uuid AS token_id,
        MIN(u.oauth2_client_identifier) AS client_id,
        SUM(u.requests) AS requests,
        SUM(u.requests) FILTER (WHERE u.status_class IN (:failedClasses)) AS failed,
        SUM(u.requests) FILTER (WHERE u.status_class = '429') AS too_many,
        SUM(u.duration_ms_total) AS duration
    FROM api_usage_day u
    WHERE u.day >= :from AND u.day < :to {$where}
    GROUP BY u.caller_key
),
activity AS (
    SELECT c.caller_key, MAX(c.peak_requests_per_minute) AS peak, MAX(c.last_request_at) AS last_request_at
    FROM api_caller_day c
    WHERE c.day >= :from AND c.day < :to AND c.caller_key IN (SELECT caller_key FROM usage)
    GROUP BY c.caller_key
)
SELECT
    usage.caller_key,
    usage.requests,
    usage.failed,
    usage.too_many,
    usage.duration,
    activity.peak,
    activity.last_request_at,
    p.name AS player_name,
    p.code AS player_code,
    t.name AS token_name,
    cl.name AS client_name
FROM usage
LEFT JOIN activity ON activity.caller_key = usage.caller_key
LEFT JOIN player p ON p.id = usage.player_id
LEFT JOIN personal_access_token t ON t.id = usage.token_id
LEFT JOIN oauth2_client cl ON cl.identifier = usage.client_id
ORDER BY usage.requests DESC, usage.caller_key
SQL,
            $parameters,
            ['failedClasses' => ArrayParameterType::STRING],
        )->fetchAllAssociative();

        $callers = [];

        foreach ($rows as $row) {
            try {
                $caller = ApiCaller::fromKey($row['caller_key']);
            } catch (InvalidArgumentException) {
                continue;
            }

            $callers[] = new ApiUsageCallerRow(
                caller: $caller,
                playerName: $row['player_name'],
                playerCode: $row['player_code'],
                tokenName: $row['token_name'],
                clientName: $row['client_name'],
                requests: (int) $row['requests'],
                failed: (int) $row['failed'],
                tooManyRequests: (int) $row['too_many'],
                durationMsTotal: (int) $row['duration'],
                peakRequestsPerMinute: $row['peak'] !== null ? (int) $row['peak'] : null,
                lastRequestAt: $row['last_request_at'] !== null ? new DateTimeImmutable($row['last_request_at']) : null,
            );
        }

        return $callers;
    }
}
