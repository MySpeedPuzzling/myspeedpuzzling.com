<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Query;

use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Ramsey\Uuid\Uuid;

/**
 * Waiting event links (WaitingEventLink, docs/features/competitions-management/creation-wizard.md "Backfill") - which
 * are due (their event or series is publicly visible now: IsCompetitionPubliclyVisible / IsSeriesPubliclyVisible) or
 * dead (rejected), and which of a player's times wait for which event (the player's own "Link my results" page only).
 */
readonly final class GetWaitingEventLinks
{
    public function __construct(
        private Connection $database,
    ) {
    }

    /**
     * @return array{due: list<string>, rejected: list<string>}
     */
    public function dueAndRejected(): array
    {
        $visible = IsCompetitionPubliclyVisible::SQL_CONDITION;
        $seriesVisible = IsSeriesPubliclyVisible::SQL_CONDITION;

        /** @var list<array{id: string, due: bool, rejected: bool}> $rows */
        $rows = $this->database->fetchAllAssociative(<<<SQL
SELECT w.id,
    CASE WHEN w.competition_id IS NOT NULL THEN {$visible} ELSE {$seriesVisible} END AS due,
    COALESCE(c.rejected_at IS NOT NULL, false) OR COALESCE(cs.rejected_at IS NOT NULL, false) AS rejected
FROM waiting_event_link w
LEFT JOIN competition c ON c.id = w.competition_id
LEFT JOIN competition_series cs ON cs.id = COALESCE(c.series_id, w.series_id)
ORDER BY w.created_at, w.id
SQL);

        $due = [];
        $rejected = [];

        foreach ($rows as $row) {
            if ((bool) $row['rejected']) {
                $rejected[] = $row['id'];
            } elseif ((bool) $row['due']) {
                $due[] = $row['id'];
            }
        }

        return ['due' => $due, 'rejected' => $rejected];
    }

    /**
     * Which of these times wait, and for what (the event's or series' name) - the "Link my results" page of their owner
     *
     * @param list<string> $timeIds
     * @return array<string, string> time id => the waiting target's name
     */
    public function forTimes(array $timeIds): array
    {
        $timeIds = array_values(array_filter($timeIds, Uuid::isValid(...)));

        if ($timeIds === []) {
            return [];
        }

        /** @var list<array{time_id: string, name: string}> $rows */
        $rows = $this->database->fetchAllAssociative(<<<SQL
SELECT w.puzzle_solving_time_id AS time_id, COALESCE(c.name, s.name) AS name
FROM waiting_event_link w
LEFT JOIN competition c ON c.id = w.competition_id
LEFT JOIN competition_series s ON s.id = w.series_id
WHERE w.puzzle_solving_time_id IN (:timeIds)
SQL, ['timeIds' => $timeIds], ['timeIds' => ArrayParameterType::STRING]);

        $waiting = [];

        foreach ($rows as $row) {
            $waiting[$row['time_id']] = $row['name'];
        }

        return $waiting;
    }

    /**
     * How many times wait for this event (or series) - its creator's Done page
     */
    public function countFor(null|string $competitionId, null|string $seriesId): int
    {
        if ($competitionId !== null && Uuid::isValid($competitionId)) {
            return self::int($this->database->fetchOne('SELECT COUNT(*) FROM waiting_event_link WHERE competition_id = :id', ['id' => $competitionId]));
        }

        if ($seriesId !== null && Uuid::isValid($seriesId)) {
            return self::int($this->database->fetchOne('SELECT COUNT(*) FROM waiting_event_link WHERE series_id = :id', ['id' => $seriesId]));
        }

        return 0;
    }

    private static function int(mixed $value): int
    {
        return is_numeric($value) ? (int) $value : 0;
    }
}
