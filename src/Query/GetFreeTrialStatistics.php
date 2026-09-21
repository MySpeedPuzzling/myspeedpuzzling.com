<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Query;

use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use SpeedPuzzling\Web\Results\AnnouncementModalStatistics;
use SpeedPuzzling\Web\Results\FreeTrialFunnel;

/**
 * Admin numbers of the free trial (docs/features/free-trial/README.md, "Measurement"): how many players
 * were shown each announcement modal, and what became of the trials.
 */
readonly final class GetFreeTrialStatistics
{
    public function __construct(
        private Connection $database,
    ) {
    }

    /**
     * @return list<AnnouncementModalStatistics>
     */
    public function modalImpressions(DateTimeImmutable $now): array
    {
        $query = <<<SQL
SELECT
    modal,
    COUNT(*) AS displayed,
    COUNT(seen_at) AS seen,
    COUNT(*) FILTER (WHERE displayed_at > :dayAgo) AS displayed_last_24_hours,
    COUNT(*) FILTER (WHERE displayed_at > :weekAgo) AS displayed_last_7_days,
    MIN(displayed_at) AS first_displayed_at,
    MAX(displayed_at) AS last_displayed_at
FROM player_modal_impression
GROUP BY modal
ORDER BY modal
SQL;

        /** @var list<array{modal: string, displayed: int|string, seen: int|string, displayed_last_24_hours: int|string, displayed_last_7_days: int|string, first_displayed_at: null|string, last_displayed_at: null|string}> $rows */
        $rows = $this->database
            ->executeQuery($query, [
                'dayAgo' => $now->modify('-24 hours')->format('Y-m-d H:i:s'),
                'weekAgo' => $now->modify('-7 days')->format('Y-m-d H:i:s'),
            ])
            ->fetchAllAssociative();

        return array_map(static fn (array $row): AnnouncementModalStatistics => new AnnouncementModalStatistics(
            modal: $row['modal'],
            displayed: (int) $row['displayed'],
            seen: (int) $row['seen'],
            displayedLast24Hours: (int) $row['displayed_last_24_hours'],
            displayedLast7Days: (int) $row['displayed_last_7_days'],
            firstDisplayedAt: $row['first_displayed_at'] !== null ? new DateTimeImmutable($row['first_displayed_at']) : null,
            lastDisplayedAt: $row['last_displayed_at'] !== null ? new DateTimeImmutable($row['last_displayed_at']) : null,
        ), $rows);
    }

    public function funnel(DateTimeImmutable $now): FreeTrialFunnel
    {
        $totalsQuery = <<<SQL
SELECT
    (SELECT COUNT(*) FROM player WHERE NOT EXISTS (SELECT 1 FROM membership WHERE membership.player_id = player.id)) AS eligible_players,
    COUNT(*) AS started,
    COUNT(*) FILTER (WHERE trial_ends_at > :now) AS running,
    COUNT(*) FILTER (WHERE trial_ends_at <= :now) AS ended,
    COUNT(trial_converted_at) AS subscribed_total,
    COUNT(*) FILTER (WHERE trial_converted_at <= trial_ends_at) AS subscribed_during_trial
FROM membership
WHERE trial_started_at IS NOT NULL
SQL;

        /** @var array{eligible_players: int|string, started: int|string, running: int|string, ended: int|string, subscribed_total: int|string, subscribed_during_trial: int|string} $totals */
        $totals = $this->database
            ->executeQuery($totalsQuery, ['now' => $now->format('Y-m-d H:i:s')])
            ->fetchAssociative();

        $bySourceQuery = <<<SQL
SELECT
    COALESCE(trial_source, 'unknown') AS source,
    COUNT(*) AS started,
    COUNT(trial_converted_at) AS subscribed
FROM membership
WHERE trial_started_at IS NOT NULL
GROUP BY 1
ORDER BY 2 DESC
SQL;

        /** @var list<array{source: string, started: int|string, subscribed: int|string}> $bySource */
        $bySource = $this->database->executeQuery($bySourceQuery)->fetchAllAssociative();

        $startedBySource = [];
        $subscribedBySource = [];

        foreach ($bySource as $row) {
            $startedBySource[$row['source']] = (int) $row['started'];
            $subscribedBySource[$row['source']] = (int) $row['subscribed'];
        }

        return new FreeTrialFunnel(
            eligiblePlayers: (int) $totals['eligible_players'],
            started: (int) $totals['started'],
            running: (int) $totals['running'],
            ended: (int) $totals['ended'],
            subscribedDuringTrial: (int) $totals['subscribed_during_trial'],
            subscribedTotal: (int) $totals['subscribed_total'],
            startedBySource: $startedBySource,
            subscribedBySource: $subscribedBySource,
        );
    }
}
