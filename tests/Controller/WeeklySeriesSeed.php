<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Controller;

use Doctrine\DBAL\Connection;
use SpeedPuzzling\Web\Tests\SeriesEditionScenario;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * A high-frequency series for the page tests (docs/features/events-page/high-frequency-series.md): "Lantern Weekly
 * Jam", online, with $past past editions (one every 3 days, the newest a week ago) and $upcoming coming ones (from the
 * day after tomorrow on, every $upcomingEveryDays days),
 * each with one round - solo, every tenth pairs - holding the puzzle "Copper Lighthouse". The series and the puzzle go
 * through the messages (SeriesEditionScenario); the editions, rounds and round puzzles are three bulk INSERTs - 200
 * AddEdition messages would run 200 reconciles. Made-up names only.
 */
final class WeeklySeriesSeed
{
    /**
     * @return array{seriesId: string, slug: string, puzzleId: string}
     */
    public static function create(ContainerInterface $container, int $past = 200, int $upcoming = 3, int $upcomingEveryDays = 7): array
    {
        $scenario = new SeriesEditionScenario($container);
        $seriesId = $scenario->series('Lantern Weekly Jam');
        $puzzleId = $scenario->puzzle('Copper Lighthouse');

        // @phpstan-ignore symfonyContainer.privateService (the test container exposes it)
        $connection = $container->get(Connection::class);
        $parameters = ['series' => $seriesId, 'past' => $past, 'total' => $past + $upcoming, 'puzzle' => $puzzleId, 'every' => $upcomingEveryDays];

        $connection->executeStatement(
            "INSERT INTO competition (id, name, slug, series_id, is_online, created_at, date_from, date_to)
             SELECT gen_random_uuid(), 'Jam No. ' || n, 'jam-no-' || n, CAST(:series AS UUID), true, NOW(), day, day
             FROM (
                 SELECT n, CASE WHEN n <= :past THEN CURRENT_DATE - ((:past - n) * 3 + 7) ELSE CURRENT_DATE + 2 + (n - :past) * :every END AS day
                 FROM generate_series(1, :total) n
             ) days",
            $parameters,
        );

        $connection->executeStatement(
            "INSERT INTO competition_round (id, competition_id, name, minutes_limit, starts_at, slug, timezone, category)
             SELECT gen_random_uuid(), c.id, 'Main', 60, CAST(c.date_from AS DATE) + TIME '17:00', 'main', 'Europe/Berlin',
                 CASE WHEN CAST(substring(c.name FROM 8) AS INT) % 10 = 0 THEN 'duo' ELSE 'solo' END
             FROM competition c
             WHERE c.series_id = CAST(:series AS UUID)",
            $parameters,
        );

        $connection->executeStatement(
            'INSERT INTO competition_round_puzzle (id, round_id, puzzle_id)
             SELECT gen_random_uuid(), cr.id, CAST(:puzzle AS UUID)
             FROM competition_round cr
             INNER JOIN competition c ON c.id = cr.competition_id
             WHERE c.series_id = CAST(:series AS UUID)',
            $parameters,
        );

        $slug = $connection->fetchOne('SELECT slug FROM competition_series WHERE id = :id', ['id' => $seriesId]);
        assert(is_string($slug));

        return ['seriesId' => $seriesId, 'slug' => $slug, 'puzzleId' => $puzzleId];
    }
}
