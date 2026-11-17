<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Query;

use DateTimeImmutable;
use DateTimeZone;
use Doctrine\DBAL\Connection;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Exceptions\CompetitionSeriesNotFound;
use SpeedPuzzling\Web\Results\EventWizardSeriesEdition;
use SpeedPuzzling\Web\Results\EventWizardSeriesState;
use SpeedPuzzling\Web\Value\RoundCategory;

/**
 * What the event creation wizard's series path (W3) reads of a series. Organiser tooling - the callers check
 * COMPETITION_SERIES_EDIT; drafts (`is_draft` of the series and of its editions) are read on purpose: the wizard works on
 * a draft series and its draft editions.
 */
readonly final class GetEventWizardSeries
{
    public function __construct(
        private Connection $database,
    ) {
    }

    /**
     * @throws CompetitionSeriesNotFound
     */
    public function state(string $seriesId): EventWizardSeriesState
    {
        if (Uuid::isValid($seriesId) === false) {
            throw new CompetitionSeriesNotFound();
        }

        $query = <<<SQL
SELECT
    cs.is_draft,
    cs.added_by_player_id,
    (SELECT COUNT(*) FROM competition c WHERE c.series_id = cs.id) AS editions_count
FROM competition_series cs
WHERE cs.id = :seriesId
SQL;

        /** @var false|array{is_draft: bool, added_by_player_id: null|string, editions_count: int|string} $row */
        $row = $this->database->executeQuery($query, ['seriesId' => $seriesId])->fetchAssociative();

        if ($row === false) {
            throw new CompetitionSeriesNotFound();
        }

        return new EventWizardSeriesState(
            seriesId: strtolower($seriesId),
            isDraft: (bool) $row['is_draft'],
            editionsCount: (int) $row['editions_count'],
            addedByPlayerId: $row['added_by_player_id'] !== null ? strtolower($row['added_by_player_id']) : null,
        );
    }

    /**
     * Every edition of the series, drafts included, by day (undated last) - with its first round
     *
     * @return list<EventWizardSeriesEdition>
     */
    public function editions(string $seriesId): array
    {
        if (Uuid::isValid($seriesId) === false) {
            return [];
        }

        $query = <<<SQL
SELECT
    c.id AS competition_id,
    c.name,
    c.is_draft,
    c.date_from,
    (SELECT COUNT(*) FROM competition_round cr WHERE cr.competition_id = c.id) AS rounds_count,
    (SELECT COUNT(*) FROM competition_round_puzzle crp JOIN competition_round cr ON cr.id = crp.round_id WHERE cr.competition_id = c.id) AS puzzles_count,
    r.id AS round_id,
    r.name AS round_name,
    r.starts_at AS round_starts_at,
    r.timezone AS round_timezone,
    r.category AS round_category,
    r.team_size AS round_team_size,
    r.minutes_limit AS round_minutes_limit,
    r.start_time_known AS round_start_time_known
FROM competition c
LEFT JOIN LATERAL (
    SELECT cr.* FROM competition_round cr WHERE cr.competition_id = c.id ORDER BY cr.starts_at, cr.id LIMIT 1
) r ON TRUE
WHERE c.series_id = :seriesId
ORDER BY COALESCE(c.date_from, r.starts_at) NULLS LAST, c.id
SQL;

        /** @var list<array{competition_id: string, name: string, is_draft: bool, date_from: null|string, rounds_count: int|string, puzzles_count: int|string, round_id: null|string, round_name: null|string, round_starts_at: null|string, round_timezone: null|string, round_category: null|string, round_team_size: null|int|string, round_minutes_limit: null|int|string, round_start_time_known: null|bool}> $rows */
        $rows = $this->database->executeQuery($query, ['seriesId' => $seriesId])->fetchAllAssociative();
        $utc = new DateTimeZone('UTC');

        return array_map(static fn (array $row): EventWizardSeriesEdition => new EventWizardSeriesEdition(
            competitionId: $row['competition_id'],
            name: $row['name'],
            isDraft: (bool) $row['is_draft'],
            dateFrom: $row['date_from'] !== null ? new DateTimeImmutable($row['date_from'], $utc) : null,
            roundsCount: (int) $row['rounds_count'],
            puzzlesCount: (int) $row['puzzles_count'],
            roundId: $row['round_id'],
            roundName: $row['round_name'],
            roundStartsAt: $row['round_starts_at'] !== null ? new DateTimeImmutable($row['round_starts_at'], $utc) : null,
            roundTimezone: $row['round_timezone'],
            roundCategory: $row['round_category'] !== null ? RoundCategory::from($row['round_category']) : null,
            roundTeamSize: $row['round_team_size'] !== null ? (int) $row['round_team_size'] : null,
            roundMinutesLimit: $row['round_minutes_limit'] !== null ? (int) $row['round_minutes_limit'] : null,
            roundStartTimeKnown: $row['round_start_time_known'] ?? true,
        ), $rows);
    }
}
