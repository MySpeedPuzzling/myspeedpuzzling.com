<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Query;

use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Exceptions\AnalyticsStudioNotFound;
use SpeedPuzzling\Web\Results\AnalyticsStudioEventRound;
use SpeedPuzzling\Web\Results\AnalyticsStudioRound;
use SpeedPuzzling\Web\Value\RoundCategory;

/**
 * The round of an analytics studio (docs/features/competitions-management/analytics-studio.md §2.2) - one statement:
 * the round with its stopwatch, its event's names and settings and the event's slugged rounds (as JSON). The WHERE
 * embeds IsCompetitionPubliclyVisible::SQL_CONDITION (an edition of a draft or unapproved series included), so a round
 * of an event that is not public - for its team too - or a round without a slug is AnalyticsStudioNotFound.
 *
 * @phpstan-type Row array{id: string, competition_id: string, name: string, slug: string, category: string, starts_at: string, minutes_limit: null|int, stopwatch_status: null|string, stopwatch_started_at: null|string, stopwatch_first_started_at: null|string, stopwatch_stopped_at: null|string, results_published_at: null|string, table_numbers_off: bool, puzzle_format: string, puzzles_to_solve: null|int, event_name: string, event_slug: null|string, series_name: null|string, series_slug: null|string, live_results: bool, event_rounds: null|string}
 */
readonly final class GetAnalyticsStudioRound
{
    public function __construct(
        private Connection $database,
    ) {
    }

    /**
     * @throws AnalyticsStudioNotFound
     */
    public function byId(string $roundId): AnalyticsStudioRound
    {
        if (Uuid::isValid($roundId) === false) {
            throw new AnalyticsStudioNotFound();
        }

        return $this->fetch('cr.id = :roundId', ['roundId' => strtolower($roundId)]);
    }

    /**
     * @throws AnalyticsStudioNotFound
     */
    public function bySlug(string $competitionId, string $roundSlug): AnalyticsStudioRound
    {
        if (Uuid::isValid($competitionId) === false) {
            throw new AnalyticsStudioNotFound();
        }

        return $this->fetch('cr.competition_id = :competitionId AND cr.slug = :roundSlug', [
            'competitionId' => strtolower($competitionId),
            'roundSlug' => $roundSlug,
        ]);
    }

    /**
     * @param array<string, string> $parameters
     * @throws AnalyticsStudioNotFound
     */
    private function fetch(string $where, array $parameters): AnalyticsStudioRound
    {
        $visible = IsCompetitionPubliclyVisible::SQL_CONDITION;

        /** @var Row|false $row */
        $row = $this->database->fetchAssociative(
            <<<SQL
SELECT
    cr.id,
    cr.competition_id,
    cr.name,
    cr.slug,
    cr.category,
    cr.starts_at,
    cr.minutes_limit,
    cr.stopwatch_status,
    cr.stopwatch_started_at,
    cr.stopwatch_first_started_at,
    cr.stopwatch_stopped_at,
    cr.results_published_at,
    cr.table_numbers_off,
    cr.puzzle_format,
    cr.puzzles_to_solve,
    c.name AS event_name,
    c.slug AS event_slug,
    cs.name AS series_name,
    cs.slug AS series_slug,
    (c.competitor_pages AND c.competitor_pages_live_results) AS live_results,
    (
        SELECT json_agg(json_build_object(
            'id', other.id,
            'name', other.name,
            'slug', other.slug,
            'category', other.category,
            'starts_at', other.starts_at,
            'published', other.results_published_at IS NOT NULL,
            'puzzle_format', other.puzzle_format,
            'puzzles_to_solve', other.puzzles_to_solve
        ) ORDER BY other.starts_at, other.name, other.id)
        FROM competition_round other
        WHERE other.competition_id = cr.competition_id
            AND other.slug IS NOT NULL
    )::text AS event_rounds
FROM competition_round cr
INNER JOIN competition c ON c.id = cr.competition_id
LEFT JOIN competition_series cs ON cs.id = c.series_id
WHERE {$where}
    AND cr.slug IS NOT NULL
    AND {$visible}
SQL,
            $parameters,
        );

        if ($row === false) {
            throw new AnalyticsStudioNotFound();
        }

        /** @var list<array{id: string, name: string, slug: string, category: string, starts_at: string, published: bool, puzzle_format: string, puzzles_to_solve: null|int}> $eventRounds */
        $eventRounds = $row['event_rounds'] !== null ? json_decode($row['event_rounds'], true, flags: JSON_THROW_ON_ERROR) : [];

        return new AnalyticsStudioRound(
            id: strtolower($row['id']),
            competitionId: strtolower($row['competition_id']),
            name: $row['name'],
            slug: $row['slug'],
            category: RoundCategory::from($row['category']),
            startsAt: new DateTimeImmutable($row['starts_at']),
            minutesLimit: $row['minutes_limit'],
            stopwatchStatus: $row['stopwatch_status'],
            stopwatchStartedAt: self::moment($row['stopwatch_started_at']),
            stopwatchFirstStartedAt: self::moment($row['stopwatch_first_started_at']),
            stopwatchStoppedAt: self::moment($row['stopwatch_stopped_at']),
            resultsPublishedAt: self::moment($row['results_published_at']),
            tableNumbersOff: $row['table_numbers_off'],
            puzzleFormat: $row['puzzle_format'],
            puzzlesToSolve: $row['puzzles_to_solve'],
            eventName: $row['event_name'],
            eventSlug: $row['event_slug'],
            seriesName: $row['series_name'],
            seriesSlug: $row['series_slug'],
            liveResults: $row['live_results'],
            eventRounds: array_map(
                static fn (array $round): AnalyticsStudioEventRound => new AnalyticsStudioEventRound(
                    id: strtolower($round['id']),
                    name: $round['name'],
                    slug: $round['slug'],
                    category: RoundCategory::from($round['category']),
                    startsAt: new DateTimeImmutable($round['starts_at']),
                    published: $round['published'],
                    isPick: $round['puzzle_format'] === 'pick',
                    puzzlesToSolve: $round['puzzles_to_solve'],
                ),
                $eventRounds,
            ),
        );
    }

    private static function moment(null|string $value): null|DateTimeImmutable
    {
        return $value !== null ? new DateTimeImmutable($value) : null;
    }
}
