<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\MessageHandler;

use DateTimeImmutable;
use DateTimeZone;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Message\SnapshotActivityDailySummary;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * Rolls one UTC day of player_activity_day into per-locale aggregate rows.
 * Idempotent: the day's summary rows are replaced wholesale, so re-runs and
 * backfills are safe. The "active membership" condition mirrors
 * GetPlayerProfile / PlayerProfile::fromDatabaseRow.
 */
#[AsMessageHandler]
readonly final class SnapshotActivityDailySummaryHandler
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private ClockInterface $clock,
    ) {
    }

    /**
     * @return int number of summary rows written
     */
    public function __invoke(SnapshotActivityDailySummary $message): int
    {
        $now = $this->clock->now()->setTimezone(new DateTimeZone('UTC'));

        $day = $message->day ?? $now->modify('-1 day')->format('Y-m-d');
        $dayStart = new DateTimeImmutable($day . ' 00:00:00', new DateTimeZone('UTC'));
        $dayEnd = $dayStart->modify('+1 day');

        $connection = $this->entityManager->getConnection();

        /** @var array<array{locale: string, active_players: int|string, active_members: int|string}> $activityRows */
        $activityRows = $connection->executeQuery(
            <<<SQL
SELECT
    COALESCE(p.locale, 'unknown') AS locale,
    COUNT(*) AS active_players,
    COUNT(*) FILTER (WHERE
        (m.ends_at IS NULL AND m.billing_period_ends_at IS NOT NULL)
        OR GREATEST(
            COALESCE(m.ends_at, m.billing_period_ends_at, '1970-01-01'::timestamp),
            COALESCE(m.granted_until, '1970-01-01'::timestamp)
        ) > :now
    ) AS active_members
FROM player_activity_day a
JOIN player p ON p.id = a.player_id
LEFT JOIN membership m ON m.player_id = p.id
WHERE a.day = :day
GROUP BY 1
SQL,
            ['day' => $day, 'now' => $now->format('Y-m-d H:i:sP')],
        )->fetchAllAssociative();

        /** @var array<array{locale: string, new_registrations: int|string}> $registrationRows */
        $registrationRows = $connection->executeQuery(
            <<<SQL
SELECT COALESCE(p.locale, 'unknown') AS locale, COUNT(*) AS new_registrations
FROM player p
WHERE p.registered_at >= :dayStart AND p.registered_at < :dayEnd
GROUP BY 1
SQL,
            [
                'dayStart' => $dayStart->format('Y-m-d H:i:sP'),
                'dayEnd' => $dayEnd->format('Y-m-d H:i:sP'),
            ],
        )->fetchAllAssociative();

        /** @var array<string, array{active_players: int, active_members: int, new_registrations: int}> $byLocale */
        $byLocale = [];

        foreach ($activityRows as $row) {
            $byLocale[$row['locale']] = [
                'active_players' => (int) $row['active_players'],
                'active_members' => (int) $row['active_members'],
                'new_registrations' => 0,
            ];
        }

        foreach ($registrationRows as $row) {
            $byLocale[$row['locale']] ??= [
                'active_players' => 0,
                'active_members' => 0,
                'new_registrations' => 0,
            ];
            $byLocale[$row['locale']]['new_registrations'] = (int) $row['new_registrations'];
        }

        // Replace the day wholesale - stale locales from a previous run must not linger
        $connection->executeStatement(
            'DELETE FROM activity_daily_summary WHERE day = :day',
            ['day' => $day],
        );

        foreach ($byLocale as $locale => $counts) {
            $connection->executeStatement(
                'INSERT INTO activity_daily_summary (id, day, locale, active_players, active_members, new_registrations, computed_at)
                 VALUES (:id, :day, :locale, :activePlayers, :activeMembers, :newRegistrations, :computedAt)',
                [
                    'id' => Uuid::uuid7()->toString(),
                    'day' => $day,
                    'locale' => $locale,
                    'activePlayers' => $counts['active_players'],
                    'activeMembers' => $counts['active_members'],
                    'newRegistrations' => $counts['new_registrations'],
                    'computedAt' => $now->format('Y-m-d H:i:sP'),
                ],
            );
        }

        return count($byLocale);
    }
}
