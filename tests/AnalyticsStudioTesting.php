<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests;

use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;
use Ramsey\Uuid\Uuid;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Contracts\Cache\CacheInterface;

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
        self::assertInstanceOf(CacheInterface::class, $cache);
        self::assertTrue(method_exists($cache, 'clear'));
        $cache->clear();
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
}
