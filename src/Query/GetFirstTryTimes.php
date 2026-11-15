<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Query;

use DateTimeImmutable;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Psr\Clock\ClockInterface;
use SpeedPuzzling\Web\Results\FirstTryConflict;
use SpeedPuzzling\Web\Results\FirstTryPerson;
use SpeedPuzzling\Web\Results\FirstTryPuzzle;
use SpeedPuzzling\Web\Results\FirstTryTime;
use SpeedPuzzling\Web\Results\LateFirstTry;
use SpeedPuzzling\Web\Services\HiddenPlayers;
use SpeedPuzzling\Web\Services\PrivateProfileAccess;
use SpeedPuzzling\Web\Value\DatePrecision;

/**
 * Who took part in which result of a puzzle, for the first-try rules (docs/features/first-try-integrity.md).
 *
 * A person takes part in a solo result as its player and in a pair/team result as a member of its
 * puzzling_team - the tracker is always one of the members. Guests are left out of every rule: they are a
 * name, not a person we could tell apart.
 *
 * Nobody is filtered out: a blocked or private player still counts for the rules, they are only masked in
 * what the viewer is shown (FirstTryPerson).
 */
readonly final class GetFirstTryTimes
{
    public function __construct(
        private Connection $database,
        private ClockInterface $clock,
        private HiddenPlayers $hiddenPlayers,
        private PrivateProfileAccess $privateProfileAccess,
    ) {
    }

    /**
     * Every result of the puzzle any of the given players took part in.
     *
     * @param list<string> $playerIds
     * @param null|string $viewerPlayerId null = whoever is signed in (the forms). Given = that player, whoever is
     *     signed in or nobody at all: a background worker has no viewer, so the blocklist would hide nobody and the
     *     allow lists would reveal nobody (the import's writer - docs/features/data-import/writer.md "First try")
     * @return list<FirstTryTime> oldest first
     */
    public function ofPlayersOnPuzzle(string $puzzleId, array $playerIds, null|string $exceptTimeId = null, null|string $viewerPlayerId = null): array
    {
        if ($playerIds === []) {
            return [];
        }

        $exceptTime = $exceptTimeId !== null ? 'AND pst.id <> :exceptTimeId' : '';

        $query = <<<SQL
WITH relevant AS (
    SELECT pst.id
    FROM puzzle_solving_time pst
    WHERE pst.puzzle_id = :puzzleId
        AND pst.puzzling_team_id IS NULL
        AND pst.player_id IN (:playerIds)
    UNION
    SELECT pst.id
    FROM puzzling_team_member member
    INNER JOIN puzzle_solving_time pst ON pst.puzzling_team_id = member.team_id
    WHERE member.player_id IN (:playerIds)
        AND pst.puzzle_id = :puzzleId
)
SELECT {$this->timeColumns($viewerPlayerId !== null)}
FROM relevant
INNER JOIN puzzle_solving_time pst ON pst.id = relevant.id
{$this->participantsJoin()}
WHERE TRUE {$exceptTime}
ORDER BY COALESCE(pst.finished_at, pst.tracked_at), pst.id, participant.position
SQL;

        $parameters = ['puzzleId' => $puzzleId, 'playerIds' => $playerIds];

        if ($exceptTimeId !== null) {
            $parameters['exceptTimeId'] = $exceptTimeId;
        }

        if ($viewerPlayerId !== null) {
            $parameters['viewerId'] = $viewerPlayerId;
        }

        return array_values($this->hydrateTimes($this->database->fetchAllAssociative($query, $parameters, [
            'playerIds' => ArrayParameterType::STRING,
        ])));
    }

    /**
     * How many puzzles the player holds more than one first try of - the profile banner.
     */
    public function conflictCountOf(string $playerId): int
    {
        $count = $this->database->fetchOne('SELECT ' . $this->conflictCountSql(), ['playerId' => $playerId]);

        return is_numeric($count) ? (int) $count : 0;
    }

    /**
     * The conflict count as a scalar subquery taking :playerId - GetPlayerReviewCounts folds it into the
     * banner's single query.
     */
    public function conflictCountSql(): string
    {
        return <<<SQL
(
    WITH {$this->playerTimesCte()}
    SELECT COUNT(*) FROM (
        SELECT puzzle_id FROM mine WHERE first_attempt = true GROUP BY puzzle_id HAVING COUNT(*) > 1
    ) conflict
)
SQL;
    }

    /**
     * @return list<string> the results of the puzzle the player took part in that are marked as a first try
     */
    public function markedTimeIdsOf(string $playerId, string $puzzleId): array
    {
        $query = <<<SQL
WITH {$this->playerTimesCte()}
SELECT id FROM mine WHERE first_attempt = true AND puzzle_id = :puzzleId ORDER BY solved_at, id
SQL;

        /** @var list<string> $ids */
        $ids = $this->database->fetchFirstColumn($query, ['playerId' => $playerId, 'puzzleId' => $puzzleId]);

        return $ids;
    }

    /**
     * @return list<FirstTryConflict>
     */
    public function conflictsOf(string $playerId): array
    {
        $query = <<<SQL
WITH {$this->playerTimesCte()},
conflict AS (
    SELECT puzzle_id FROM mine WHERE first_attempt = true GROUP BY puzzle_id HAVING COUNT(*) > 1
),
unmarked AS (
    SELECT puzzle_id, MIN(solved_at) AS solved_at
    FROM mine
    WHERE first_attempt = false AND puzzle_id IN (SELECT puzzle_id FROM conflict)
    GROUP BY puzzle_id
)
SELECT
    {$this->timeColumns()},
    {$this->puzzleColumns()},
    unmarked.solved_at AS earliest_unmarked_at
FROM mine
INNER JOIN conflict ON conflict.puzzle_id = mine.puzzle_id
INNER JOIN puzzle_solving_time pst ON pst.id = mine.id
INNER JOIN puzzle ON puzzle.id = pst.puzzle_id
INNER JOIN manufacturer ON manufacturer.id = puzzle.manufacturer_id
LEFT JOIN unmarked ON unmarked.puzzle_id = mine.puzzle_id
{$this->participantsJoin()}
WHERE mine.first_attempt = true
ORDER BY puzzle.name, puzzle.id, COALESCE(pst.finished_at, pst.tracked_at), pst.id, participant.position
SQL;

        /** @var list<array<string, mixed>> $rows */
        $rows = $this->database->fetchAllAssociative($query, [
            'playerId' => $playerId,
            'now' => $this->clock->now()->format('Y-m-d H:i:s'),
        ]);

        $times = $this->hydrateTimes($rows);

        /** @var array<string, array{puzzle: FirstTryPuzzle, times: array<string, FirstTryTime>, unmarked: null|string}> $byPuzzle */
        $byPuzzle = [];

        foreach ($rows as $row) {
            $puzzle = $this->hydratePuzzle($row);
            $byPuzzle[$puzzle->puzzleId] ??= [
                'puzzle' => $puzzle,
                'times' => [],
                'unmarked' => is_string($row['earliest_unmarked_at'] ?? null) ? $row['earliest_unmarked_at'] : null,
            ];

            $timeId = $this->string($row, 'time_id');
            $byPuzzle[$puzzle->puzzleId]['times'][$timeId] = $times[$timeId];
        }

        $conflicts = [];

        foreach ($byPuzzle as $conflict) {
            $conflictTimes = array_values($conflict['times']);
            $earlierUnmarked = $conflict['unmarked'] !== null ? new DateTimeImmutable($conflict['unmarked']) : null;

            // Only worth a word when it is from a day before every marked one
            if ($earlierUnmarked !== null && $earlierUnmarked->format('Y-m-d') >= $conflictTimes[0]->solvedDay()) {
                $earlierUnmarked = null;
            }

            $conflicts[] = new FirstTryConflict($conflict['puzzle'], $conflictTimes, $earlierUnmarked);
        }

        return $conflicts;
    }

    /**
     * The player's first tries logged after they had solved the puzzle on an earlier day already. Puzzles with
     * a conflict are left out (sorting those out comes first), and so is whatever the player said is fine.
     *
     * Only the player's own earlier solves count here: a teammate's history is theirs to review.
     *
     * @return list<LateFirstTry>
     */
    public function lateFirstTriesOf(string $playerId): array
    {
        $query = <<<SQL
WITH {$this->playerTimesCte()},
conflict AS (
    SELECT puzzle_id FROM mine WHERE first_attempt = true GROUP BY puzzle_id HAVING COUNT(*) > 1
),
late AS (
    SELECT
        marked.id,
        (
            SELECT MIN(earlier.solved_at)
            FROM mine earlier
            WHERE earlier.puzzle_id = marked.puzzle_id
                AND earlier.id <> marked.id
                AND CAST(earlier.solved_at AS DATE) < CAST(marked.solved_at AS DATE)
        ) AS earlier_solved_at
    FROM mine marked
    WHERE marked.first_attempt = true
        AND marked.puzzle_id NOT IN (SELECT puzzle_id FROM conflict)
        AND NOT EXISTS (
            SELECT 1 FROM first_try_review_dismissal dismissal
            WHERE dismissal.player_id = :playerId AND dismissal.solving_time_id = marked.id
        )
)
SELECT
    {$this->timeColumns()},
    {$this->puzzleColumns()},
    late.earlier_solved_at
FROM late
INNER JOIN puzzle_solving_time pst ON pst.id = late.id
INNER JOIN puzzle ON puzzle.id = pst.puzzle_id
INNER JOIN manufacturer ON manufacturer.id = puzzle.manufacturer_id
{$this->participantsJoin()}
WHERE late.earlier_solved_at IS NOT NULL
ORDER BY COALESCE(pst.finished_at, pst.tracked_at) DESC, pst.id, participant.position
SQL;

        /** @var list<array<string, mixed>> $rows */
        $rows = $this->database->fetchAllAssociative($query, [
            'playerId' => $playerId,
            'now' => $this->clock->now()->format('Y-m-d H:i:s'),
        ]);

        $times = $this->hydrateTimes($rows);
        $late = [];

        foreach ($rows as $row) {
            $timeId = $this->string($row, 'time_id');

            $late[$timeId] ??= new LateFirstTry(
                $this->hydratePuzzle($row),
                $times[$timeId],
                new DateTimeImmutable($this->string($row, 'earlier_solved_at')),
            );
        }

        return array_values($late);
    }

    /**
     * `mine`: every result the player took part in - solo as its player, pair/team as a member.
     */
    private function playerTimesCte(): string
    {
        return <<<SQL
mine AS (
    SELECT pst.id, pst.puzzle_id, pst.first_attempt, COALESCE(pst.finished_at, pst.tracked_at) AS solved_at
    FROM puzzle_solving_time pst
    WHERE pst.player_id = :playerId AND pst.puzzling_team_id IS NULL
    UNION ALL
    SELECT pst.id, pst.puzzle_id, pst.first_attempt, COALESCE(pst.finished_at, pst.tracked_at)
    FROM puzzling_team_member member
    INNER JOIN puzzle_solving_time pst ON pst.puzzling_team_id = member.team_id
    WHERE member.player_id = :playerId
)
SQL;
    }

    /**
     * One row per person of the result `pst`: its player for a solo result, every member for a pair/team.
     */
    private function participantsJoin(): string
    {
        return <<<SQL
INNER JOIN LATERAL (
    SELECT pst.player_id AS player_id, CAST(NULL AS VARCHAR) AS guest_name, 0 AS position
    WHERE pst.puzzling_team_id IS NULL
    UNION ALL
    SELECT member.player_id, member.guest_name, CASE WHEN member.player_id = pst.player_id THEN 0 ELSE member.position + 1 END
    FROM puzzling_team_member member
    WHERE member.team_id = pst.puzzling_team_id
) participant ON TRUE
LEFT JOIN player participant_player ON participant_player.id = participant.player_id
SQL;
    }

    /**
     * @param bool $forGivenViewer the statement binds `:viewerId` and decides for that player: the rule
     *     PrivateProfileAccess applies (a private player is shown to whom they put on their allow list, unless either
     *     blocks the other), written out for that id, and HiddenPlayers' rule (whom the viewer blocks) as a column
     */
    private function timeColumns(bool $forGivenViewer = false): string
    {
        $isPrivate = $this->privateProfileAccess->sqlIsPrivate('participant_player');
        $hidden = '';

        if ($forGivenViewer) {
            $isPrivate = <<<SQL
(participant_player.is_private AND NOT EXISTS (
        SELECT 1 FROM private_profile_viewer
        WHERE private_profile_viewer.owner_id = participant_player.id
            AND private_profile_viewer.viewer_id = :viewerId
            AND private_profile_viewer.owner_id <> private_profile_viewer.viewer_id
            AND NOT EXISTS (
                SELECT 1 FROM user_block
                WHERE (user_block.blocker_id = private_profile_viewer.owner_id AND user_block.blocked_id = private_profile_viewer.viewer_id)
                    OR (user_block.blocker_id = private_profile_viewer.viewer_id AND user_block.blocked_id = private_profile_viewer.owner_id)
            )
    ))
SQL;
            $hidden = <<<SQL
,
    EXISTS (
        SELECT 1 FROM user_block
        WHERE user_block.blocker_id = :viewerId AND user_block.blocked_id = participant.player_id
    ) AS participant_hidden
SQL;
        }

        return <<<SQL
pst.id AS time_id,
    COALESCE(pst.finished_at, pst.tracked_at) AS solved_at,
    pst.finished_at_precision,
    pst.tracked_at,
    pst.player_id AS tracker_id,
    pst.first_attempt,
    pst.seconds_to_solve,
    participant.player_id AS participant_player_id,
    participant.guest_name AS participant_guest_name,
    participant_player.code AS participant_code,
    CASE WHEN {$isPrivate} THEN NULL ELSE participant_player.name END AS participant_name,
    COALESCE({$isPrivate}, false) AS participant_private{$hidden}
SQL;
    }

    private function puzzleColumns(): string
    {
        return <<<SQL
puzzle.id AS puzzle_id,
    puzzle.name AS puzzle_name,
    puzzle.pieces_count AS puzzle_pieces_count,
    CASE WHEN puzzle.hide_image_until IS NOT NULL AND puzzle.hide_image_until > CAST(:now AS TIMESTAMP) THEN NULL ELSE puzzle.image END AS puzzle_image,
    manufacturer.name AS manufacturer_name
SQL;
    }

    /**
     * @param list<array<string, mixed>> $rows one per person of each result, in order
     * @return array<string, FirstTryTime>
     */
    private function hydrateTimes(array $rows): array
    {
        /** @var array<string, array{row: array<string, mixed>, people: list<FirstTryPerson>}> $collected */
        $collected = [];

        foreach ($rows as $row) {
            $timeId = $this->string($row, 'time_id');
            $collected[$timeId] ??= ['row' => $row, 'people' => []];

            $playerId = is_string($row['participant_player_id'] ?? null) ? $row['participant_player_id'] : null;
            // A statement for a given viewer says it itself (timeColumns()); otherwise whoever is signed in decides
            $hidden = array_key_exists('participant_hidden', $row) ? $row['participant_hidden'] === true : $this->hiddenPlayers->isHidden($playerId);
            $private = ($row['participant_private'] ?? false) === true;
            $name = is_string($row['participant_name'] ?? null) ? $row['participant_name'] : null;
            $code = is_string($row['participant_code'] ?? null) ? $row['participant_code'] : null;

            $collected[$timeId]['people'][] = new FirstTryPerson(
                playerId: $playerId,
                name: $hidden || $private ? null : $name,
                code: $hidden || $private ? null : $code,
                guestName: is_string($row['participant_guest_name'] ?? null) ? $row['participant_guest_name'] : null,
                masked: $hidden || $private,
            );
        }

        $times = [];

        foreach ($collected as $timeId => $time) {
            $row = $time['row'];
            $seconds = $row['seconds_to_solve'] ?? null;

            $times[$timeId] = new FirstTryTime(
                timeId: $timeId,
                solvedAt: new DateTimeImmutable($this->string($row, 'solved_at')),
                datePrecision: DatePrecision::ofStored(is_string($row['finished_at_precision'] ?? null) ? $row['finished_at_precision'] : null),
                firstAttempt: ($row['first_attempt'] ?? false) === true,
                secondsToSolve: is_int($seconds) ? $seconds : null,
                people: $time['people'],
                trackedAt: new DateTimeImmutable($this->string($row, 'tracked_at')),
                trackerPlayerId: $this->string($row, 'tracker_id'),
            );
        }

        return $times;
    }

    /**
     * @param array<string, mixed> $row
     */
    private function hydratePuzzle(array $row): FirstTryPuzzle
    {
        $pieces = $row['puzzle_pieces_count'] ?? 0;

        return new FirstTryPuzzle(
            puzzleId: $this->string($row, 'puzzle_id'),
            name: $this->string($row, 'puzzle_name'),
            manufacturerName: $this->string($row, 'manufacturer_name'),
            piecesCount: is_int($pieces) ? $pieces : 0,
            image: is_string($row['puzzle_image'] ?? null) ? $row['puzzle_image'] : null,
        );
    }

    /**
     * @param array<string, mixed> $row
     */
    private function string(array $row, string $column): string
    {
        $value = $row[$column] ?? null;
        assert(is_string($value));

        return $value;
    }
}
