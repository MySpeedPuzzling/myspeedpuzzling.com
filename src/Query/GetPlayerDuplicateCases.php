<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Query;

use DateTimeImmutable;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Psr\Clock\ClockInterface;
use SpeedPuzzling\Web\Results\AutoRemovedResult;
use SpeedPuzzling\Web\Results\DuplicateReviewCase;
use SpeedPuzzling\Web\Results\DuplicateReviewCopy;
use SpeedPuzzling\Web\Results\FirstTryPerson;
use SpeedPuzzling\Web\Results\FirstTryPuzzle;
use SpeedPuzzling\Web\Services\HiddenPlayers;
use SpeedPuzzling\Web\Services\PrivateProfileAccess;
use SpeedPuzzling\Web\Value\DuplicateCaseStatus;
use SpeedPuzzling\Web\Value\DuplicateKind;
use SpeedPuzzling\Web\Value\DuplicateTier;
use SpeedPuzzling\Web\Value\RemovedResultSnapshot;

/**
 * The player's own duplicate cases and automatic removals, for the review page and the recap
 * (docs/features/duplicate-results.md, "Review page"). Reads the stored cases of one person plus their two
 * results by id - a case whose copy is gone already is left out (the detection closes it).
 *
 * People of a pair/team the viewer may not see are masked like on the first-try conflicts: a private player
 * without the viewer on their allow list, or one the viewer blocked, is "a puzzler".
 */
readonly final class GetPlayerDuplicateCases
{
    public const int AUTO_REMOVED_DAYS = 90;

    public function __construct(
        private Connection $database,
        private ClockInterface $clock,
        private HiddenPlayers $hiddenPlayers,
        private PrivateProfileAccess $privateProfileAccess,
    ) {
    }

    /**
     * @return list<DuplicateReviewCase> the likely ones first (Tier A/B), then Tier C; newest first within
     */
    public function openOf(string $playerId): array
    {
        return $this->cases($playerId, null);
    }

    /**
     * The open cases with this result in them - the recap right after saving it.
     *
     * @return list<DuplicateReviewCase>
     */
    public function openOfTime(string $playerId, string $timeId): array
    {
        return $this->cases($playerId, $timeId);
    }

    /**
     * Copies of the player's results removed automatically in the last 90 days and not brought back.
     *
     * @return list<AutoRemovedResult> newest first
     */
    public function autoRemovalsOf(string $playerId): array
    {
        $query = <<<SQL
SELECT id, kept_time_id, removed_at, snapshot
FROM result_auto_removal
WHERE player_id = :playerId
    AND undone_at IS NULL
    AND removed_at > :since
ORDER BY removed_at DESC, id
SQL;

        /** @var list<array{id: string, kept_time_id: string, removed_at: string, snapshot: string}> $rows */
        $rows = $this->database->fetchAllAssociative($query, [
            'playerId' => $playerId,
            'since' => $this->clock->now()->modify('-' . self::AUTO_REMOVED_DAYS . ' days')->format('Y-m-d H:i:s'),
        ]);

        return array_map(static function (array $row): AutoRemovedResult {
            /** @var array<string, mixed> $data */
            $data = json_decode($row['snapshot'], true, flags: JSON_THROW_ON_ERROR);
            $snapshot = RemovedResultSnapshot::fromArray($data);

            return new AutoRemovedResult(
                removalId: $row['id'],
                puzzleId: $snapshot->puzzleId,
                puzzleName: $snapshot->puzzleName,
                secondsToSolve: $snapshot->secondsToSolve,
                solvedAt: $snapshot->finishedAt ?? $snapshot->trackedAt,
                savedAt: $snapshot->trackedAt,
                removedAt: new DateTimeImmutable($row['removed_at']),
                keptTimeId: $row['kept_time_id'],
            );
        }, $rows);
    }

    /**
     * @return list<DuplicateReviewCase>
     */
    private function cases(string $playerId, null|string $timeId): array
    {
        $timeCondition = $timeId !== null ? 'AND :timeId IN (c.time_a_id, c.time_b_id)' : '';

        $query = <<<SQL
SELECT
    c.id AS case_id,
    c.tier,
    c.kind,
    c.time_a_id,
    c.time_b_id,
    puzzle.id AS puzzle_id,
    puzzle.name AS puzzle_name,
    puzzle.pieces_count AS puzzle_pieces_count,
    CASE WHEN puzzle.hide_image_until IS NOT NULL AND puzzle.hide_image_until > CAST(:now AS TIMESTAMP) THEN NULL ELSE puzzle.image END AS puzzle_image,
    manufacturer.name AS manufacturer_name
FROM result_duplicate_case c
INNER JOIN puzzle_solving_time a ON a.id = c.time_a_id
INNER JOIN puzzle_solving_time b ON b.id = c.time_b_id
INNER JOIN puzzle ON puzzle.id = a.puzzle_id
INNER JOIN manufacturer ON manufacturer.id = puzzle.manufacturer_id
WHERE c.player_id = :playerId
    AND c.status = :open
    {$timeCondition}
ORDER BY CASE c.tier WHEN :certain THEN 0 WHEN :strong THEN 1 ELSE 2 END, b.tracked_at DESC, c.id
SQL;

        $parameters = [
            'playerId' => $playerId,
            'open' => DuplicateCaseStatus::Open->value,
            'certain' => DuplicateTier::Certain->value,
            'strong' => DuplicateTier::Strong->value,
            'now' => $this->clock->now()->format('Y-m-d H:i:s'),
        ];

        if ($timeId !== null) {
            $parameters['timeId'] = $timeId;
        }

        /** @var list<array{case_id: string, tier: string, kind: string, time_a_id: string, time_b_id: string, puzzle_id: string, puzzle_name: string, puzzle_pieces_count: int, puzzle_image: null|string, manufacturer_name: string}> $rows */
        $rows = $this->database->fetchAllAssociative($query, $parameters);

        if ($rows === []) {
            return [];
        }

        $copies = $this->copies([...array_column($rows, 'time_a_id'), ...array_column($rows, 'time_b_id')]);
        $cases = [];

        foreach ($rows as $row) {
            if (!isset($copies[$row['time_a_id']], $copies[$row['time_b_id']])) {
                continue;
            }

            $cases[] = new DuplicateReviewCase(
                caseId: $row['case_id'],
                tier: DuplicateTier::from($row['tier']),
                kind: DuplicateKind::from($row['kind']),
                puzzle: new FirstTryPuzzle(
                    puzzleId: $row['puzzle_id'],
                    name: $row['puzzle_name'],
                    manufacturerName: $row['manufacturer_name'],
                    piecesCount: $row['puzzle_pieces_count'],
                    image: $row['puzzle_image'],
                ),
                older: $copies[$row['time_a_id']],
                newer: $copies[$row['time_b_id']],
            );
        }

        return $cases;
    }

    /**
     * @param list<string> $timeIds
     * @return array<string, DuplicateReviewCopy>
     */
    private function copies(array $timeIds): array
    {
        $isPrivate = $this->privateProfileAccess->sqlIsPrivate('participant_player');

        $query = <<<SQL
SELECT
    pst.id AS time_id,
    pst.player_id AS tracker_id,
    COALESCE(pst.finished_at, pst.tracked_at) AS solved_at,
    pst.tracked_at,
    pst.seconds_to_solve,
    pst.comment,
    pst.finished_puzzle_photo,
    pst.first_attempt,
    pst.unboxed,
    competition.name AS competition_name,
    participant.player_id AS participant_player_id,
    participant.guest_name AS participant_guest_name,
    participant_player.code AS participant_code,
    CASE WHEN {$isPrivate} THEN NULL ELSE participant_player.name END AS participant_name,
    COALESCE({$isPrivate}, false) AS participant_private
FROM puzzle_solving_time pst
LEFT JOIN competition ON competition.id = pst.competition_id
INNER JOIN LATERAL (
    SELECT pst.player_id AS player_id, CAST(NULL AS VARCHAR) AS guest_name, 0 AS position
    WHERE pst.puzzling_team_id IS NULL
    UNION ALL
    SELECT member.player_id, member.guest_name, CASE WHEN member.player_id = pst.player_id THEN 0 ELSE member.position + 1 END
    FROM puzzling_team_member member
    WHERE member.team_id = pst.puzzling_team_id
) participant ON TRUE
LEFT JOIN player participant_player ON participant_player.id = participant.player_id
WHERE pst.id IN (:timeIds)
ORDER BY pst.id, participant.position
SQL;

        /** @var list<array{time_id: string, tracker_id: string, solved_at: string, tracked_at: string, seconds_to_solve: null|int, comment: null|string, finished_puzzle_photo: null|string, first_attempt: bool, unboxed: bool, competition_name: null|string, participant_player_id: null|string, participant_guest_name: null|string, participant_code: null|string, participant_name: null|string, participant_private: bool}> $rows */
        $rows = $this->database->fetchAllAssociative(
            $query,
            ['timeIds' => array_values(array_unique($timeIds))],
            ['timeIds' => ArrayParameterType::STRING],
        );

        /** @var array<string, array{row: array{time_id: string, tracker_id: string, solved_at: string, tracked_at: string, seconds_to_solve: null|int, comment: null|string, finished_puzzle_photo: null|string, first_attempt: bool, unboxed: bool, competition_name: null|string}, people: list<FirstTryPerson>}> $collected */
        $collected = [];

        foreach ($rows as $row) {
            $collected[$row['time_id']] ??= ['row' => $row, 'people' => []];

            $masked = $this->hiddenPlayers->isHidden($row['participant_player_id']) || $row['participant_private'];

            $collected[$row['time_id']]['people'][] = new FirstTryPerson(
                playerId: $row['participant_player_id'],
                name: $masked ? null : $row['participant_name'],
                code: $masked ? null : $row['participant_code'],
                guestName: $row['participant_guest_name'],
                masked: $masked,
            );
        }

        $copies = [];

        foreach ($collected as $timeId => ['row' => $row, 'people' => $people]) {
            $copies[$timeId] = new DuplicateReviewCopy(
                timeId: $timeId,
                trackerId: $row['tracker_id'],
                solvedAt: new DateTimeImmutable($row['solved_at']),
                trackedAt: new DateTimeImmutable($row['tracked_at']),
                secondsToSolve: $row['seconds_to_solve'],
                comment: $row['comment'],
                finishedPuzzlePhoto: $row['finished_puzzle_photo'],
                firstAttempt: $row['first_attempt'],
                unboxed: $row['unboxed'],
                competitionName: $row['competition_name'],
                people: $people,
            );
        }

        return $copies;
    }
}
