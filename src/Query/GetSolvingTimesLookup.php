<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Query;

use DateTimeImmutable;
use DateTimeZone;
use Doctrine\DBAL\Connection;
use SpeedPuzzling\Web\Results\DeletedSolvingTime;
use SpeedPuzzling\Web\Results\FoundSolvingTime;
use SpeedPuzzling\Web\Results\SolvingTimeLookup;
use SpeedPuzzling\Web\Value\PuzzlingType;

/**
 * Which of the given solving time ids still exist for the player - an API client checking the results it synced
 * (POST /api/v1/me/solving-times/lookup, docs/features/api/solving-time-sync.md). Two statements whatever the
 * number of ids (≤ 500): the times by primary key, then the player's tombstones of the rest.
 *
 * "For the player" = tracked by them or a pair/team result they are a registered member of - the same times as
 * their GET /me/results lists. Suspicious times are found too: these are the player's own results. An id of
 * somebody else's time is reported missing, never whose it is.
 */
readonly final class GetSolvingTimesLookup
{
    public function __construct(
        private Connection $database,
    ) {
    }

    /**
     * @param list<string> $timeIds valid UUIDs, lower case, each once
     */
    public function forPlayer(string $playerId, array $timeIds): SolvingTimeLookup
    {
        if ($timeIds === []) {
            return new SolvingTimeLookup([], [], []);
        }

        /** @var list<array{id: string, puzzle_id: string, puzzling_type: string, tracked_by_me: bool}> $rows */
        $rows = $this->database->executeQuery(<<<SQL
SELECT
    pst.id,
    pst.puzzle_id,
    pst.puzzling_type,
    pst.player_id = :playerId AS tracked_by_me
FROM puzzle_solving_time pst
WHERE pst.id = ANY(CAST(:timeIds AS uuid[]))
    AND (
        pst.player_id = :playerId
        OR (pst.team::jsonb -> 'puzzlers') @> jsonb_build_array(jsonb_build_object('player_id', CAST(:playerId AS UUID)))
    )
SQL, [
            'playerId' => $playerId,
            'timeIds' => self::uuidArray($timeIds),
        ])->fetchAllAssociative();

        $found = [];
        $foundIds = [];

        foreach ($rows as $row) {
            $found[] = new FoundSolvingTime(
                timeId: $row['id'],
                puzzleId: $row['puzzle_id'],
                type: PuzzlingType::from($row['puzzling_type']),
                trackedByMe: $row['tracked_by_me'],
            );
            $foundIds[$row['id']] = true;
        }

        $notFound = array_values(array_filter($timeIds, static fn (string $id): bool => !isset($foundIds[$id])));

        if ($notFound === []) {
            return new SolvingTimeLookup($found, [], []);
        }

        /** @var list<array{solving_time_id: string, deleted_at: string}> $tombstones */
        $tombstones = $this->database->executeQuery(<<<SQL
SELECT solving_time_id, MAX(deleted_at) AS deleted_at
FROM solving_time_tombstone
WHERE player_id = :playerId
    AND solving_time_id = ANY(CAST(:timeIds AS uuid[]))
GROUP BY solving_time_id
SQL, [
            'playerId' => $playerId,
            'timeIds' => self::uuidArray($notFound),
        ])->fetchAllAssociative();

        $deleted = [];
        $deletedIds = [];

        foreach ($tombstones as $tombstone) {
            $deleted[] = new DeletedSolvingTime(
                timeId: $tombstone['solving_time_id'],
                deletedAt: new DateTimeImmutable($tombstone['deleted_at'], new DateTimeZone('UTC')),
            );
            $deletedIds[$tombstone['solving_time_id']] = true;
        }

        return new SolvingTimeLookup(
            found: $found,
            deleted: $deleted,
            missing: array_values(array_filter($notFound, static fn (string $id): bool => !isset($deletedIds[$id]))),
        );
    }

    /**
     * @param list<string> $uuids
     */
    private static function uuidArray(array $uuids): string
    {
        return '{' . implode(',', $uuids) . '}';
    }
}
