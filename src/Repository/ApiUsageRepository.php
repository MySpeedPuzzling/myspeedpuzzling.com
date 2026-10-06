<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Repository;

use DateTimeImmutable;
use DateTimeZone;
use Doctrine\ORM\EntityManagerInterface;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Entity\ApiCallerDay;
use SpeedPuzzling\Web\Entity\ApiUsageDay;
use SpeedPuzzling\Web\Value\ApiCallerActivity;
use SpeedPuzzling\Web\Value\ApiUsageCount;
use SpeedPuzzling\Web\Value\ApiUsageSnapshot;

/**
 * Stores the API usage counters' daily totals (docs/features/api/usage-statistics.md).
 *
 * Native upserts instead of persist() - a documented bulk exception: one run copies
 * hundreds of counter rows, merges them into existing ones and races overlapping
 * runs on the unique keys, which the unit of work cannot do. Every value is a total
 * so far (never a delta) and is merged with GREATEST, so re-running a copy, two
 * runs at once or a run after a Redis restart can never lower or double a stored
 * number. Rows of a player or token deleted since the request are skipped.
 */
readonly final class ApiUsageRepository
{
    private const int ROWS_PER_STATEMENT = 500;

    public function __construct(
        private EntityManagerInterface $entityManager,
    ) {
    }

    public function storeSnapshot(ApiUsageSnapshot $snapshot): void
    {
        $day = $snapshot->day->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d');

        foreach (array_chunk($snapshot->counts, self::ROWS_PER_STATEMENT) as $chunk) {
            $this->storeCounts($day, $chunk);
        }

        foreach (array_chunk($snapshot->callers, self::ROWS_PER_STATEMENT) as $chunk) {
            $this->storeCallers($day, $chunk);
        }
    }

    public function deleteOlderThan(DateTimeImmutable $before): int
    {
        $day = $before->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d');
        $deleted = 0;

        foreach ([ApiUsageDay::class, ApiCallerDay::class] as $entityClass) {
            $result = $this->entityManager->createQueryBuilder()
                ->delete($entityClass, 'u')
                ->where('u.day < :before')
                ->setParameter('before', $day)
                ->getQuery()
                ->execute();

            $deleted += is_int($result) ? $result : 0;
        }

        return $deleted;
    }

    /**
     * @param list<ApiUsageCount> $counts
     */
    private function storeCounts(string $day, array $counts): void
    {
        $values = [];
        $parameters = ['day' => $day];

        foreach ($counts as $i => $count) {
            $values[] = "(:id{$i}, :callerKey{$i}, :playerId{$i}, :tokenId{$i}, :clientId{$i}, :operation{$i}, :statusClass{$i}, :requests{$i}, :duration{$i})";
            $parameters += [
                "id{$i}" => Uuid::uuid7()->toString(),
                "callerKey{$i}" => $count->caller->key(),
                "playerId{$i}" => $count->caller->playerId,
                "tokenId{$i}" => $count->caller->personalAccessTokenId,
                "clientId{$i}" => $count->caller->oauth2ClientIdentifier,
                "operation{$i}" => $count->operation,
                "statusClass{$i}" => $count->statusClass->value,
                "requests{$i}" => $count->requests,
                "duration{$i}" => $count->durationMsTotal,
            ];
        }

        $valuesSql = implode(",\n", $values);

        $this->entityManager->getConnection()->executeStatement(
            <<<SQL
INSERT INTO api_usage_day (id, day, caller_key, player_id, personal_access_token_id, oauth2_client_identifier, operation, status_class, requests, duration_ms_total)
SELECT v.id::uuid, CAST(:day AS date), v.caller_key, v.player_id::uuid, v.token_id::uuid, v.client_id, v.operation, v.status_class, v.requests::int, v.duration::bigint
FROM (VALUES {$valuesSql}) AS v(id, caller_key, player_id, token_id, client_id, operation, status_class, requests, duration)
WHERE (v.player_id IS NULL OR EXISTS (SELECT 1 FROM player p WHERE p.id = v.player_id::uuid))
  AND (v.token_id IS NULL OR EXISTS (SELECT 1 FROM personal_access_token t WHERE t.id = v.token_id::uuid))
ON CONFLICT (day, caller_key, operation, status_class) DO UPDATE SET
    requests = GREATEST(api_usage_day.requests, EXCLUDED.requests),
    duration_ms_total = GREATEST(api_usage_day.duration_ms_total, EXCLUDED.duration_ms_total)
SQL,
            $parameters,
        );
    }

    /**
     * @param list<ApiCallerActivity> $callers
     */
    private function storeCallers(string $day, array $callers): void
    {
        $values = [];
        $parameters = ['day' => $day];

        foreach ($callers as $i => $activity) {
            $values[] = "(:id{$i}, :callerKey{$i}, :playerId{$i}, :tokenId{$i}, :clientId{$i}, :peak{$i}, :lastRequestAt{$i})";
            $parameters += [
                "id{$i}" => Uuid::uuid7()->toString(),
                "callerKey{$i}" => $activity->caller->key(),
                "playerId{$i}" => $activity->caller->playerId,
                "tokenId{$i}" => $activity->caller->personalAccessTokenId,
                "clientId{$i}" => $activity->caller->oauth2ClientIdentifier,
                "peak{$i}" => $activity->peakRequestsPerMinute,
                "lastRequestAt{$i}" => $activity->lastRequestAt->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:sP'),
            ];
        }

        $valuesSql = implode(",\n", $values);

        $this->entityManager->getConnection()->executeStatement(
            <<<SQL
INSERT INTO api_caller_day (id, day, caller_key, player_id, personal_access_token_id, oauth2_client_identifier, peak_requests_per_minute, last_request_at)
SELECT v.id::uuid, CAST(:day AS date), v.caller_key, v.player_id::uuid, v.token_id::uuid, v.client_id, v.peak::int, v.last_request_at::timestamptz
FROM (VALUES {$valuesSql}) AS v(id, caller_key, player_id, token_id, client_id, peak, last_request_at)
WHERE (v.player_id IS NULL OR EXISTS (SELECT 1 FROM player p WHERE p.id = v.player_id::uuid))
  AND (v.token_id IS NULL OR EXISTS (SELECT 1 FROM personal_access_token t WHERE t.id = v.token_id::uuid))
ON CONFLICT (day, caller_key) DO UPDATE SET
    peak_requests_per_minute = GREATEST(api_caller_day.peak_requests_per_minute, EXCLUDED.peak_requests_per_minute),
    last_request_at = GREATEST(api_caller_day.last_request_at, EXCLUDED.last_request_at)
SQL,
            $parameters,
        );
    }
}
