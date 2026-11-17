<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests;

use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Services\AnalyticsStudio\AnalyticsStudioWatch;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;

/**
 * Helpers of the analytics studio's tests (docs/features/competitions-management/analytics-studio.md): its cache pool is
 * on disk in tests (it must outlive the kernel reboots between requests, like production's Redis) - so every test
 * starts from an empty pool, never from what an earlier test computed from its rolled-back rows.
 */
trait AnalyticsStudioTesting
{
    protected function clearAnalyticsStudioCache(): void
    {
        $cache = self::getContainer()->get('analytics_studio_cache');
        $cache->clear();
    }

    /**
     * The studio's watch marker of this kernel (its pool is per kernel in tests - config/packages/test/cache.php).
     */
    protected function studioWatch(): AnalyticsStudioWatch
    {
        return new AnalyticsStudioWatch(self::getContainer()->get('analytics_studio_watch_cache'));
    }

    /**
     * @return array<string, mixed>
     */
    protected function studioJson(KernelBrowser $browser, string $url, int $status = 200): array
    {
        $browser->request('GET', $url);
        self::assertResponseStatusCodeSame($status, $url . "\n" . substr((string) $browser->getResponse()->getContent(), 0, 2000));

        if ($status !== 200) {
            return [];
        }

        /** @var array<string, mixed> $json */
        $json = json_decode((string) $browser->getResponse()->getContent(), true, flags: JSON_THROW_ON_ERROR);

        return $json;
    }

    /**
     * @param array<string, string> $parameters
     */
    protected function studioString(string $sql, array $parameters): string
    {
        $value = $this->studioDatabase()->fetchOne($sql, $parameters);
        self::assertIsString($value);

        return $value;
    }

    protected function studioDatabase(): Connection
    {
        return self::getContainer()->get(Connection::class);
    }

    protected function seedStudioTime(
        string $playerId,
        string $puzzleId,
        null|int $seconds,
        string $finishedAt,
        bool $firstAttempt = false,
        bool $suspicious = false,
        null|int $piecesPlaced = null,
        null|string $roundId = null,
        null|string $precision = null,
    ): string {
        $id = Uuid::uuid7()->toString();
        $moment = new DateTimeImmutable($finishedAt);

        $this->studioDatabase()->executeStatement(
            <<<SQL
INSERT INTO puzzle_solving_time (id, player_id, puzzle_id, seconds_to_solve, pieces_placed, seconds_spent, tracked_at, finished_at, finished_at_precision, verified, first_attempt, suspicious, competition_round_id, puzzling_type, puzzlers_count)
VALUES (:id, :playerId, :puzzleId, :seconds, :piecesPlaced, :secondsSpent, :trackedAt, :finishedAt, :precision, true, :firstAttempt, :suspicious, :roundId, 'solo', 1)
SQL,
            [
                'id' => $id,
                'playerId' => $playerId,
                'puzzleId' => $puzzleId,
                'seconds' => $seconds,
                'piecesPlaced' => $piecesPlaced,
                'secondsSpent' => $piecesPlaced !== null ? 3600 : null,
                'trackedAt' => $moment->format('Y-m-d H:i:s'),
                'finishedAt' => $moment->format('Y-m-d H:i:s'),
                'precision' => $precision,
                'firstAttempt' => $firstAttempt,
                'suspicious' => $suspicious,
                'roundId' => $roundId,
            ],
            ['firstAttempt' => ParameterType::BOOLEAN, 'suspicious' => ParameterType::BOOLEAN],
        );

        return $id;
    }

    /**
     * A value deep in a decoded answer - every step must be an array holding the key.
     */
    protected static function dig(mixed $json, string|int ...$path): mixed
    {
        foreach ($path as $key) {
            self::assertIsArray($json, 'not an array at ' . $key);
            self::assertArrayHasKey($key, $json);
            $json = $json[$key];
        }

        return $json;
    }

    /**
     * @return array<mixed>
     */
    protected static function digArray(mixed $json, string|int ...$path): array
    {
        $value = self::dig($json, ...$path);
        self::assertIsArray($value);

        return $value;
    }

    /**
     * One column of a list of rows of a decoded answer.
     *
     * @return list<mixed>
     */
    protected static function column(mixed $rows, string $column): array
    {
        self::assertIsArray($rows);
        $values = [];

        foreach ($rows as $row) {
            $values[] = self::dig($row, $column);
        }

        return $values;
    }

    /**
     * The rows of a decoded list keyed by one of their columns.
     *
     * @return array<string, mixed>
     */
    protected static function keyed(mixed $rows, string $column): array
    {
        self::assertIsArray($rows);
        $keyed = [];

        foreach ($rows as $row) {
            $keyed[(string) self::scalar(self::dig($row, $column))] = $row;
        }

        return $keyed;
    }

    private static function scalar(mixed $value): string
    {
        self::assertTrue(is_string($value) || is_int($value));

        return (string) $value;
    }
}
