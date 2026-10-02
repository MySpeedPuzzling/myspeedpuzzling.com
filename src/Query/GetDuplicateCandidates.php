<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Query;

use DateTimeImmutable;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use SpeedPuzzling\Web\Results\DuplicateCandidate;
use SpeedPuzzling\Web\Results\DuplicateCandidateTime;

/**
 * Every pair of results that can be a duplicate case, site-wide (docs/features/duplicate-results.md, "Detection").
 *
 * Judged per person: a person's results are their solo results plus every pair/team result they are a registered
 * member of - guests are a name, not a person, and are ignored (like GetFirstTryTimes). A pair = the same person,
 * the same puzzle, the same time to the second, and one of: the same day, saved within an hour, or two pair/team
 * results that are a teammate copy (different trackers) or the same group of the same tracker - those on any day.
 * The same time on two different puzzles never comes back from here (a catalogue signal, not a duplicate).
 *
 * Two statements on purpose: the self-join over all results (~1.3 s on production) finds the ~1k pairs, the
 * second one reads everything the classifier needs for just those. As one statement the planner's estimate for
 * the per-pair subqueries is so high that JIT compilation alone doubled the run time.
 */
readonly final class GetDuplicateCandidates
{
    public function __construct(
        private Connection $database,
    ) {
    }

    /**
     * @return list<DuplicateCandidate> ordered by person, then by when the older copy was saved
     */
    public function all(): array
    {
        return $this->find('', '', []);
    }

    /**
     * The same pairs, only of the given people on one puzzle - the detection right after a result was saved
     * or edited, and the re-check before an automatic removal. Rides on the (player_id, puzzle_id) index.
     *
     * @param list<string> $personIds
     * @return list<DuplicateCandidate> ordered by person, then by when the older copy was saved
     */
    public function ofPeopleOnPuzzle(string $puzzleId, array $personIds): array
    {
        if ($personIds === []) {
            return [];
        }

        return $this->find(
            'AND pst.puzzle_id = :puzzleId AND pst.player_id IN (:personIds)',
            'AND pst.puzzle_id = :puzzleId AND member.player_id IN (:personIds)',
            ['puzzleId' => $puzzleId, 'personIds' => $personIds],
        );
    }

    /**
     * @param array<string, mixed> $parameters
     * @return list<DuplicateCandidate>
     */
    private function find(string $soloScope, string $groupScope, array $parameters): array
    {
        $pairsQuery = <<<SQL
WITH person_result AS (
    SELECT pst.id, pst.player_id AS person_id, pst.player_id AS tracker_id, pst.puzzling_team_id AS team_id,
        pst.puzzle_id, pst.seconds_to_solve, COALESCE(pst.finished_at, pst.tracked_at)::date AS solved_day, pst.tracked_at
    FROM puzzle_solving_time pst
    WHERE pst.puzzling_team_id IS NULL
        AND pst.seconds_to_solve IS NOT NULL
        {$soloScope}
    UNION ALL
    SELECT pst.id, member.player_id, pst.player_id, pst.puzzling_team_id,
        pst.puzzle_id, pst.seconds_to_solve, COALESCE(pst.finished_at, pst.tracked_at)::date, pst.tracked_at
    FROM puzzle_solving_time pst
    INNER JOIN puzzling_team_member member ON member.team_id = pst.puzzling_team_id AND member.player_id IS NOT NULL
    WHERE pst.seconds_to_solve IS NOT NULL
        {$groupScope}
)
SELECT a.person_id, a.id AS a_id, b.id AS b_id
FROM person_result a
INNER JOIN person_result b
    ON b.person_id = a.person_id
    AND b.puzzle_id = a.puzzle_id
    AND b.seconds_to_solve = a.seconds_to_solve
    AND (b.tracked_at, b.id) > (a.tracked_at, a.id)
    AND (
        a.solved_day = b.solved_day
        OR b.tracked_at - a.tracked_at < INTERVAL '1 hour'
        OR (
            a.team_id IS NOT NULL
            AND b.team_id IS NOT NULL
            AND (a.tracker_id <> b.tracker_id OR a.team_id = b.team_id)
        )
    )
SQL;

        /** @var list<array{person_id: string, a_id: string, b_id: string}> $pairs */
        $pairs = $this->database->fetchAllAssociative(
            $pairsQuery,
            $parameters,
            isset($parameters['personIds']) ? ['personIds' => ArrayParameterType::STRING] : [],
        );

        if ($pairs === []) {
            return [];
        }

        $detailsQuery = <<<SQL
WITH pair AS (
    SELECT *
    FROM unnest(CAST(:personIds AS uuid[]), CAST(:olderIds AS uuid[]), CAST(:newerIds AS uuid[])) AS pair(person_id, a_id, b_id)
)
SELECT
    pair.person_id,
    puzzle.id AS puzzle_id,
    puzzle.name AS puzzle_name,
    a.seconds_to_solve,
    {$this->timeColumns('a')},
    {$this->timeColumns('b')},
    (
        EXISTS (
            SELECT 1
            FROM puzzle_solving_time other
            WHERE other.player_id = pair.person_id
                AND other.puzzle_id = a.puzzle_id
                AND other.puzzling_team_id IS NULL
                AND other.id NOT IN (a.id, b.id)
                AND other.seconds_to_solve IS DISTINCT FROM a.seconds_to_solve
                AND COALESCE(other.finished_at, other.tracked_at)::date IN (COALESCE(a.finished_at, a.tracked_at)::date, COALESCE(b.finished_at, b.tracked_at)::date)
        )
        OR EXISTS (
            SELECT 1
            FROM puzzling_team_member other_member
            INNER JOIN puzzle_solving_time other ON other.puzzling_team_id = other_member.team_id
            WHERE other_member.player_id = pair.person_id
                AND other.puzzle_id = a.puzzle_id
                AND other.id NOT IN (a.id, b.id)
                AND other.seconds_to_solve IS DISTINCT FROM a.seconds_to_solve
                AND COALESCE(other.finished_at, other.tracked_at)::date IN (COALESCE(a.finished_at, a.tracked_at)::date, COALESCE(b.finished_at, b.tracked_at)::date)
        )
    ) AS practice_session,
    CASE WHEN a.player_id = b.player_id AND b.tracked_at - a.tracked_at < INTERVAL '1 hour' THEN EXISTS (
        SELECT 1
        FROM puzzle_solving_time other
        WHERE other.player_id = a.player_id
            AND other.tracked_at > a.tracked_at
            AND other.tracked_at < b.tracked_at
    ) END AS saved_in_between
FROM pair
INNER JOIN puzzle_solving_time a ON a.id = pair.a_id
INNER JOIN puzzle_solving_time b ON b.id = pair.b_id
INNER JOIN puzzle ON puzzle.id = a.puzzle_id
INNER JOIN player a_tracker ON a_tracker.id = a.player_id
INNER JOIN player b_tracker ON b_tracker.id = b.player_id
ORDER BY pair.person_id, a.tracked_at, a.id, b.tracked_at, b.id
SQL;

        $rows = $this->database->fetchAllAssociative($detailsQuery, [
            'personIds' => self::uuidArray(array_column($pairs, 'person_id')),
            'olderIds' => self::uuidArray(array_column($pairs, 'a_id')),
            'newerIds' => self::uuidArray(array_column($pairs, 'b_id')),
        ]);

        return array_map(fn (array $row): DuplicateCandidate => $this->hydrate($row), $rows);
    }

    private function timeColumns(string $alias): string
    {
        return <<<SQL
{$alias}.id AS {$alias}_id,
    {$alias}.player_id AS {$alias}_tracker_id,
    {$alias}_tracker.name AS {$alias}_tracker_name,
    {$alias}_tracker.code AS {$alias}_tracker_code,
    {$alias}.puzzling_team_id AS {$alias}_team_id,
    (
        SELECT json_agg(json_build_object('id', member.player_id, 'name', member_player.name, 'code', member_player.code) ORDER BY member.position)
        FROM puzzling_team_member member
        INNER JOIN player member_player ON member_player.id = member.player_id
        WHERE member.team_id = {$alias}.puzzling_team_id
    ) AS {$alias}_people,
    COALESCE({$alias}.finished_at, {$alias}.tracked_at)::date::text AS {$alias}_solved_day,
    {$alias}.finished_at AS {$alias}_finished_at,
    {$alias}.tracked_at AS {$alias}_tracked_at,
    {$alias}.comment AS {$alias}_comment,
    {$alias}.finished_puzzle_photo IS NOT NULL AS {$alias}_has_photo,
    {$alias}.first_attempt AS {$alias}_first_attempt,
    {$alias}.unboxed AS {$alias}_unboxed,
    {$alias}.competition_id AS {$alias}_competition_id,
    {$alias}.competition_round_id AS {$alias}_competition_round_id
SQL;
    }

    /**
     * @param array<string, mixed> $row
     */
    private function hydrate(array $row): DuplicateCandidate
    {
        /** @var array{person_id: string, puzzle_id: string, puzzle_name: string, seconds_to_solve: int|string, practice_session: bool, saved_in_between: null|bool} $row */
        return new DuplicateCandidate(
            personId: $row['person_id'],
            puzzleId: $row['puzzle_id'],
            puzzleName: $row['puzzle_name'],
            secondsToSolve: (int) $row['seconds_to_solve'],
            older: $this->hydrateTime($row, 'a'),
            newer: $this->hydrateTime($row, 'b'),
            practiceSession: $row['practice_session'],
            savedInBetween: $row['saved_in_between'],
        );
    }

    /**
     * @param array<string, mixed> $row
     */
    private function hydrateTime(array $row, string $alias): DuplicateCandidateTime
    {
        /** @var string $timeId */
        $timeId = $row["{$alias}_id"];
        /** @var string $trackerId */
        $trackerId = $row["{$alias}_tracker_id"];
        /** @var null|string $trackerName */
        $trackerName = $row["{$alias}_tracker_name"];
        /** @var string $trackerCode */
        $trackerCode = $row["{$alias}_tracker_code"];
        /** @var null|string $teamId */
        $teamId = $row["{$alias}_team_id"];
        /** @var null|string $peopleJson */
        $peopleJson = $row["{$alias}_people"];
        /** @var string $solvedDay */
        $solvedDay = $row["{$alias}_solved_day"];
        /** @var null|string $finishedAt */
        $finishedAt = $row["{$alias}_finished_at"];
        /** @var string $trackedAt */
        $trackedAt = $row["{$alias}_tracked_at"];
        /** @var null|string $comment */
        $comment = $row["{$alias}_comment"];
        /** @var null|string $competitionId */
        $competitionId = $row["{$alias}_competition_id"];
        /** @var null|string $competitionRoundId */
        $competitionRoundId = $row["{$alias}_competition_round_id"];

        // A solo result's only person is its tracker; a group's people are its registered members (guests left out)
        if ($teamId === null || $peopleJson === null) {
            $people = [['id' => $trackerId, 'name' => $trackerName, 'code' => $trackerCode]];
        } else {
            /** @var list<array{id: string, name: null|string, code: string}> $people */
            $people = json_decode($peopleJson, true, flags: JSON_THROW_ON_ERROR);
        }

        return new DuplicateCandidateTime(
            timeId: $timeId,
            trackerId: $trackerId,
            trackerName: $trackerName,
            trackerCode: $trackerCode,
            teamId: $teamId,
            people: $people,
            solvedDay: $solvedDay,
            finishedAt: $finishedAt !== null ? new DateTimeImmutable($finishedAt) : null,
            trackedAt: new DateTimeImmutable($trackedAt),
            comment: $comment,
            hasPhoto: (bool) $row["{$alias}_has_photo"],
            firstAttempt: (bool) $row["{$alias}_first_attempt"],
            unboxed: (bool) $row["{$alias}_unboxed"],
            competitionId: $competitionId,
            competitionRoundId: $competitionRoundId,
        );
    }

    /**
     * @param list<string> $ids
     */
    private static function uuidArray(array $ids): string
    {
        return '{' . implode(',', $ids) . '}';
    }
}
