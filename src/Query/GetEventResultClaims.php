<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Query;

use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Results\EventResultClaimContext;
use SpeedPuzzling\Web\Results\EventResultSameResultCandidate;
use SpeedPuzzling\Web\Services\HiddenPlayers;
use SpeedPuzzling\Web\Services\PrivateProfileAccess;
use SpeedPuzzling\Web\Value\Puzzler;
use SpeedPuzzling\Web\Value\SolvingTimeSource;

/**
 * Who holds the spots of imported event results (docs/features/event-results-import/README.md "Claiming a spot").
 */
readonly final class GetEventResultClaims
{
    public function __construct(
        private Connection $database,
        private HiddenPlayers $hiddenPlayers,
        private PrivateProfileAccess $privateProfileAccess,
    ) {
    }

    /**
     * One spot per person per round: whether the player is in any result of the round - tracked by them or as a
     * registered member, suspicious or not (a spot is a spot) - other than $exceptTimeId (the result the claim deletes).
     */
    public function hasSpotInRound(string $playerId, string $roundId, null|string $exceptTimeId): bool
    {
        $query = <<<SQL
SELECT 1
FROM puzzle_solving_time pst
WHERE pst.competition_round_id = :roundId
    AND pst.id <> :exceptTimeId
    AND (
        pst.player_id = :playerId
        OR EXISTS (SELECT 1 FROM puzzling_team_member member WHERE member.team_id = pst.puzzling_team_id AND member.player_id = :playerId)
    )
LIMIT 1
SQL;

        return $this->database->fetchOne($query, [
            'roundId' => $roundId,
            'playerId' => $playerId,
            // A uuid nobody has, so the condition stays one shape
            'exceptTimeId' => $exceptTimeId ?? Uuid::NIL,
        ]) !== false;
    }

    /**
     * The events in which the player holds a spot of an imported result - "This was my team" is not offered there. An
     * imported event has one round, so the event stands for the round.
     *
     * @return list<string>
     */
    public function competitionsWithImportedSpot(string $playerId): array
    {
        $query = <<<SQL
SELECT DISTINCT pst.competition_id::varchar
FROM puzzling_team_member member
INNER JOIN puzzle_solving_time pst ON pst.puzzling_team_id = member.team_id
WHERE member.player_id = :playerId
    AND pst.created_via = :source
    AND pst.competition_id IS NOT NULL
SQL;

        /** @var list<string> $ids */
        $ids = $this->database->fetchFirstColumn($query, [
            'playerId' => $playerId,
            'source' => SolvingTimeSource::EventImport->value,
        ]);

        return $ids;
    }

    /**
     * Null when the result does not exist or is no imported event result.
     */
    public function context(string $timeId, null|string $viewerId): null|EventResultClaimContext
    {
        if (Uuid::isValid($timeId) === false) {
            return null;
        }

        $claimersNotHidden = $this->hiddenPlayers->sqlExclude('p.id');

        $query = <<<SQL
SELECT
    pst.id AS time_id,
    pst.puzzle_id,
    pst.seconds_to_solve,
    COALESCE(pst.finished_at, pst.tracked_at) AS solved_at,
    (pst.created_via = :source) AS imported_from_event,
    pst.first_attempt,
    pst.competition_id::varchar AS competition_id,
    competition.name AS competition_name,
    puzzling_team.name AS team_name,
    pst.puzzlers_count,
    (
        SELECT COUNT(*)
        FROM json_array_elements(pst.team -> 'puzzlers') AS slot(puzzler)
        WHERE slot.puzzler ->> 'anonymous_slot' IS NOT NULL
    ) AS anonymous_count,
    (
        SELECT JSON_AGG(
            JSON_BUILD_OBJECT(
                'player_id', p.id,
                'player_name', p.name,
                'player_code', p.code,
                'player_country', p.country,
                'player_avatar', p.avatar,
                'is_private', {$this->privateProfileAccess->sqlIsPrivate('p')}
            ) ORDER BY member.ordinality
        )
        FROM json_array_elements(pst.team -> 'puzzlers') WITH ORDINALITY AS member(puzzler, ordinality)
            INNER JOIN player p ON p.id = (member.puzzler ->> 'player_id')::uuid
        WHERE true {$claimersNotHidden}
    ) AS claimers,
    puzzle.name AS puzzle_name,
    puzzle.pieces_count,
    manufacturer.name AS manufacturer_name
FROM puzzle_solving_time pst
    INNER JOIN puzzle ON puzzle.id = pst.puzzle_id
    LEFT JOIN manufacturer ON manufacturer.id = puzzle.manufacturer_id
    LEFT JOIN competition ON competition.id = pst.competition_id
    LEFT JOIN puzzling_team ON puzzling_team.id = pst.puzzling_team_id
WHERE pst.id = :timeId
SQL;

        /**
         * @var false|array{
         *     time_id: string,
         *     puzzle_id: string,
         *     seconds_to_solve: null|int,
         *     solved_at: string,
         *     imported_from_event: null|bool,
         *     first_attempt: bool,
         *     competition_id: null|string,
         *     competition_name: null|string,
         *     team_name: null|string,
         *     puzzlers_count: int,
         *     anonymous_count: int,
         *     claimers: null|string,
         *     puzzle_name: string,
         *     pieces_count: int,
         *     manufacturer_name: null|string,
         * } $row
         */
        $row = $this->database->fetchAssociative($query, [
            'timeId' => $timeId,
            'source' => SolvingTimeSource::EventImport->value,
        ]);

        if ($row === false || $row['imported_from_event'] !== true) {
            return null;
        }

        $solvedAt = new DateTimeImmutable($row['solved_at']);

        return new EventResultClaimContext(
            timeId: $row['time_id'],
            puzzleId: $row['puzzle_id'],
            puzzleName: $row['puzzle_name'],
            manufacturerName: $row['manufacturer_name'],
            piecesCount: $row['pieces_count'],
            seconds: $row['seconds_to_solve'],
            solvedAt: $solvedAt,
            importedFromEvent: true,
            firstAttempt: $row['first_attempt'],
            competitionId: $row['competition_id'],
            competitionName: $row['competition_name'],
            teamName: $row['team_name'],
            puzzlersCount: $row['puzzlers_count'],
            anonymousCount: $row['anonymous_count'],
            claimers: $row['claimers'] !== null ? array_values(Puzzler::createPuzzlersFromJson($row['claimers'])) : [],
            sameResultCandidates: $viewerId !== null ? $this->sameResultCandidates($viewerId, $row['puzzle_id'], $timeId, $solvedAt) : [],
        );
    }

    /**
     * The viewer's own results of the puzzle - tracked by them, so theirs to delete - from the day before the event to
     * the day after: the result may have been typed in with another date.
     *
     * @return list<EventResultSameResultCandidate>
     */
    private function sameResultCandidates(string $viewerId, string $puzzleId, string $timeId, DateTimeImmutable $solvedAt): array
    {
        $query = <<<SQL
SELECT
    pst.id AS time_id,
    pst.seconds_to_solve,
    COALESCE(pst.finished_at, pst.tracked_at) AS solved_at,
    pst.puzzlers_count,
    pst.first_attempt
FROM puzzle_solving_time pst
WHERE pst.puzzle_id = :puzzleId
    AND pst.player_id = :viewerId
    AND pst.id <> :timeId
    AND pst.created_via IS DISTINCT FROM :source
    AND COALESCE(pst.finished_at, pst.tracked_at) >= :from
    AND COALESCE(pst.finished_at, pst.tracked_at) < :to
ORDER BY COALESCE(pst.finished_at, pst.tracked_at), pst.id
SQL;

        $day = $solvedAt->setTime(0, 0);

        /** @var list<array{time_id: string, seconds_to_solve: null|int, solved_at: string, puzzlers_count: int, first_attempt: bool}> $rows */
        $rows = $this->database->fetchAllAssociative($query, [
            'puzzleId' => $puzzleId,
            'viewerId' => $viewerId,
            'timeId' => $timeId,
            'source' => SolvingTimeSource::EventImport->value,
            'from' => $day->modify('-1 day')->format('Y-m-d H:i:s'),
            'to' => $day->modify('+2 days')->format('Y-m-d H:i:s'),
        ]);

        return array_map(
            static fn (array $row): EventResultSameResultCandidate => new EventResultSameResultCandidate(
                timeId: $row['time_id'],
                seconds: $row['seconds_to_solve'],
                solvedAt: new DateTimeImmutable($row['solved_at']),
                puzzlersCount: $row['puzzlers_count'],
                firstAttempt: $row['first_attempt'],
            ),
            $rows,
        );
    }
}
