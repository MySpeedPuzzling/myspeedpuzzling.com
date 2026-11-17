<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Query;

use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Psr\Clock\ClockInterface;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Results\Bracket\BracketRound;
use SpeedPuzzling\Web\Value\BracketRoundStatus;
use SpeedPuzzling\Web\Value\RoundCategory;
use SpeedPuzzling\Web\Value\RoundTimezone;

/**
 * The rounds of an event as the public bracket shows them (competition toolkit A9): name, start, zone and status -
 * nothing of their puzzles (a secret puzzle stays the round page's business) and no counts. One statement.
 *
 * Drafts are the caller's: BracketPageBuilder answers 404 for an event that is not public first.
 */
readonly final class GetBracketRounds
{
    public function __construct(
        private Connection $database,
        private ClockInterface $clock,
    ) {
    }

    /**
     * @return list<BracketRound> in schedule order (start, ties by id - the order of the event's other pages)
     */
    public function ofCompetition(string $competitionId): array
    {
        if (!Uuid::isValid($competitionId)) {
            return [];
        }

        $rows = $this->database->fetchAllAssociative(
            <<<SQL
SELECT
    cr.id,
    cr.name,
    cr.slug,
    cr.category,
    cr.starts_at,
    cr.start_time_known,
    cr.timezone,
    cr.results_published_at IS NOT NULL AS results_published,
    cr.stopwatch_status,
    cr.stopwatch_started_at,
    c.location_country_code,
    tz_cs.location_country_code AS series_country_code
FROM competition_round cr
INNER JOIN competition c ON c.id = cr.competition_id
LEFT JOIN competition_series tz_cs ON tz_cs.id = c.series_id
WHERE cr.competition_id = :competitionId
ORDER BY cr.starts_at, cr.id
SQL,
            ['competitionId' => $competitionId],
        );

        $now = $this->clock->now();

        return array_map(static function (array $row) use ($now): BracketRound {
            /** @var array{id: string, name: string, slug: null|string, category: string, starts_at: string, start_time_known: bool, timezone: null|string, results_published: bool, stopwatch_status: null|string, stopwatch_started_at: null|string, location_country_code: null|string, series_country_code: null|string} $row */
            $startsAt = new DateTimeImmutable($row['starts_at']);

            return new BracketRound(
                id: strtolower($row['id']),
                name: $row['name'],
                category: RoundCategory::from($row['category']),
                startsAt: $startsAt,
                startTimeKnown: $row['start_time_known'],
                timezone: RoundTimezone::resolve($row['timezone'], $row['location_country_code'], $row['series_country_code']),
                status: BracketRoundStatus::of(
                    $row['results_published'],
                    $row['stopwatch_status'],
                    $row['stopwatch_started_at'] !== null ? new DateTimeImmutable($row['stopwatch_started_at']) : null,
                    $startsAt,
                    $now,
                ),
                slug: $row['slug'],
            );
        }, $rows);
    }
}
