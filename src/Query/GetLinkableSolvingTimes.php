<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Query;

use DateTimeImmutable;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Results\LinkableSolvingTime;
use SpeedPuzzling\Web\Value\PuzzlingType;

/**
 * "Link my results" (docs/features/competitions-management/creation-wizard.md "Backfill"): the signed-in player's own
 * results - solo, and every pair/team result they are in (GetMyDataResults' "mine": the puzzling team's members) - that
 * may belong to the event: of one of its puzzles (any day), or solved within 7 days of its days. Never an imported
 * event result (the organizer's sheet owns it) nor an unfinished one (its event goes with its own edit). The ones of
 * the event's days first, then by the distance to them.
 *
 * Only the player's own page reads it - their own data as it is: a suspicious time is listed (with its flag), the
 * event a time is linked to now is named even when it is not public (it is their own link).
 */
readonly final class GetLinkableSolvingTimes
{
    public const int LIMIT = 60;
    private const int DAYS = 7;

    public function __construct(
        private Connection $database,
    ) {
    }

    /**
     * @param list<string> $eventPuzzleIds
     * @return list<LinkableSolvingTime>
     */
    public function forPlayer(
        string $playerId,
        null|string $competitionId,
        null|string $seriesId,
        array $eventPuzzleIds,
        null|DateTimeImmutable $dayFrom,
        null|DateTimeImmutable $dayTo,
    ): array {
        if (Uuid::isValid($playerId) === false || ($eventPuzzleIds === [] && $dayFrom === null)) {
            return [];
        }

        $dayTo ??= $dayFrom;
        $days = self::DAYS;
        $solvedDay = 'CAST(COALESCE(pst.finished_at, pst.tracked_at) AS DATE)';
        // A result known to its month or year only stands on the first day of it (DatePrecision) - never "of that day"
        $onEventDay = $dayFrom === null ? 'false' : "(pst.finished_at_precision IS NULL AND {$solvedDay} BETWEEN CAST(:dayFrom AS DATE) AND CAST(:dayTo AS DATE))";
        $near = $dayFrom === null ? 'false' : "(pst.finished_at_precision IS NULL AND {$solvedDay} BETWEEN CAST(:dayFrom AS DATE) - {$days} AND CAST(:dayTo AS DATE) + {$days})";
        $distance = $dayFrom === null ? 'CAST(NULL AS INTEGER)' : "LEAST(ABS({$solvedDay} - CAST(:dayFrom AS DATE)), ABS({$solvedDay} - CAST(:dayTo AS DATE)))";
        $linkedHere = $seriesId !== null
            // A series pick, or an explicit link to one of its dates (never rewritten into a series pick)
            ? '(pst.competition_series_id = CAST(:seriesId AS UUID) OR (pst.competition_series_id IS NULL AND c.series_id = CAST(:seriesId AS UUID)))'
            : '(pst.competition_series_id IS NULL AND pst.competition_id IS NOT DISTINCT FROM CAST(:competitionId AS UUID))';

        /** @var list<array{id: string, puzzle_id: string, puzzle_name: string, brand_name: null|string, pieces_count: int|string, seconds_to_solve: null|int|string, solved_at: string, puzzling_type: string, suspicious: bool, current_event_name: null|string, linked_here: bool, event_puzzle: bool, on_event_day: bool}> $rows */
        $rows = $this->database->fetchAllAssociative(<<<SQL
WITH mine AS (
    SELECT pst.id
    FROM puzzle_solving_time pst
    WHERE pst.player_id = :playerId
        AND pst.puzzling_team_id IS NULL
    UNION
    SELECT pst.id
    FROM puzzling_team_member member
    INNER JOIN puzzle_solving_time pst ON pst.puzzling_team_id = member.team_id
    WHERE member.player_id = :playerId
)
SELECT pst.id,
    pst.puzzle_id,
    p.name AS puzzle_name,
    m.name AS brand_name,
    p.pieces_count,
    pst.seconds_to_solve,
    COALESCE(pst.finished_at, pst.tracked_at) AS solved_at,
    pst.puzzling_type,
    pst.suspicious,
    COALESCE(c.name, cs.name) AS current_event_name,
    {$linkedHere} AS linked_here,
    pst.puzzle_id IN (:puzzleIds) AS event_puzzle,
    {$onEventDay} AS on_event_day
FROM mine
INNER JOIN puzzle_solving_time pst ON pst.id = mine.id
INNER JOIN puzzle p ON p.id = pst.puzzle_id
LEFT JOIN manufacturer m ON m.id = p.manufacturer_id
LEFT JOIN competition c ON c.id = pst.competition_id
LEFT JOIN competition_series cs ON cs.id = COALESCE(c.series_id, pst.competition_series_id)
WHERE pst.created_via IS DISTINCT FROM 'event_import'
    AND pst.pieces_placed IS NULL
    AND (pst.puzzle_id IN (:puzzleIds) OR {$near})
ORDER BY {$onEventDay} DESC, {$distance}, solved_at DESC, pst.id
LIMIT :limit
SQL, [
            'playerId' => $playerId,
            'puzzleIds' => $eventPuzzleIds !== [] ? $eventPuzzleIds : ['00000000-0000-0000-0000-000000000000'],
            ...($seriesId !== null ? ['seriesId' => $seriesId] : ['competitionId' => $competitionId]),
            ...($dayFrom !== null ? ['dayFrom' => $dayFrom->format('Y-m-d'), 'dayTo' => ($dayTo ?? $dayFrom)->format('Y-m-d')] : []),
            'limit' => self::LIMIT,
        ], ['puzzleIds' => ArrayParameterType::STRING]);

        return array_map(static fn (array $row): LinkableSolvingTime => new LinkableSolvingTime(
            timeId: (string) $row['id'],
            puzzleId: (string) $row['puzzle_id'],
            puzzleName: (string) $row['puzzle_name'],
            brandName: $row['brand_name'] !== null ? (string) $row['brand_name'] : null,
            piecesCount: (int) $row['pieces_count'],
            seconds: $row['seconds_to_solve'] !== null ? (int) $row['seconds_to_solve'] : null,
            solvedAt: new DateTimeImmutable((string) $row['solved_at']),
            puzzlingType: PuzzlingType::from((string) $row['puzzling_type']),
            currentEventName: (bool) $row['linked_here'] ? null : ($row['current_event_name'] !== null ? (string) $row['current_event_name'] : null),
            isLinkedHere: (bool) $row['linked_here'],
            isEventPuzzle: (bool) $row['event_puzzle'],
            isOnEventDay: (bool) $row['on_event_day'],
            suspicious: (bool) $row['suspicious'],
        ), $rows);
    }
}
