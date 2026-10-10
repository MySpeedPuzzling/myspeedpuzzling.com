<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Query;

use DateTimeImmutable;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use SpeedPuzzling\Web\Results\XpHistoryBlock;
use SpeedPuzzling\Web\Results\XpHistoryEntry;
use SpeedPuzzling\Web\Value\XpReason;

/**
 * XP ledger for the audit page "where did my XP come from?" — every entry with reason, amount and a link back to its
 * solve. User-facing auditability is a locked requirement. The page reads it grouped: one block per
 * solve (blocksForPlayer), newest change first, so a bonus settled today shows up on top with its solve.
 */
readonly class GetXpHistory
{
    public function __construct(
        private Connection $database,
    ) {
    }

    /**
     * @return list<XpHistoryEntry>
     */
    public function forPlayer(string $playerId, int $limit, int $offset): array
    {
        $sql = <<<SQL
SELECT
    e.reason,
    e.amount,
    e.earned_at,
    e.solving_time_id,
    pst.puzzle_id,
    p.name AS puzzle_name
FROM xp_entry e
LEFT JOIN puzzle_solving_time pst ON pst.id = e.solving_time_id
LEFT JOIN puzzle p ON p.id = pst.puzzle_id
WHERE e.player_id = :playerId
ORDER BY e.earned_at DESC, e.created_at DESC, e.id DESC
LIMIT :limit OFFSET :offset
SQL;

        /** @var list<array{reason: string, amount: int, earned_at: string, solving_time_id: null|string, puzzle_id: null|string, puzzle_name: null|string}> $rows */
        $rows = $this->database
            ->executeQuery($sql, [
                'playerId' => $playerId,
                'limit' => $limit,
                'offset' => $offset,
            ])
            ->fetchAllAssociative();

        $lines = [];

        foreach ($rows as $row) {
            $lines[] = new XpHistoryEntry(
                reason: XpReason::from($row['reason']),
                amount: $row['amount'],
                earnedAt: new DateTimeImmutable($row['earned_at']),
                solvingTimeId: $row['solving_time_id'],
                puzzleId: $row['puzzle_id'],
                puzzleName: $row['puzzle_name'],
            );
        }

        return $lines;
    }

    public function countForPlayer(string $playerId): int
    {
        $sql = <<<SQL
SELECT COUNT(*)
FROM xp_entry
WHERE player_id = :playerId
SQL;

        $value = $this->database
            ->executeQuery($sql, ['playerId' => $playerId])
            ->fetchOne();

        return is_numeric($value) ? (int) $value : 0;
    }

    /**
     * One block per solve (every line of it, settlements and the deletion included) or per stand-alone entry, the
     * block with the most recent line first. Two statements whatever the page size.
     *
     * @return list<XpHistoryBlock>
     */
    public function blocksForPlayer(string $playerId, int $limit, int $offset): array
    {
        // A page of blocks, each with the context of its solve: the puzzle, relax or timed, and how many solves of
        // that puzzle the player had before (the base line says "repeat solve") - suspicious times are not counted,
        // like the XP calculation (GetSolveXpDisplayInfo)
        $blocksSql = <<<SQL
SELECT
    blocks.solving_time_id,
    blocks.entry_id,
    pst.puzzle_id,
    p.name AS puzzle_name,
    COALESCE(pst.finished_at, pst.tracked_at) AS solved_at,
    pst.puzzling_type,
    pst.seconds_to_solve,
    (
        SELECT COUNT(*)
        FROM puzzle_solving_time earlier
        WHERE earlier.suspicious = false
          AND earlier.puzzle_id = pst.puzzle_id
          AND (
            earlier.player_id = :playerId
            OR (earlier.team::jsonb -> 'puzzlers') @> jsonb_build_array(jsonb_build_object('player_id', CAST(:playerId AS UUID)))
          )
          AND (COALESCE(earlier.finished_at, earlier.tracked_at), earlier.id) < (COALESCE(pst.finished_at, pst.tracked_at), pst.id)
    ) AS earlier_solves
FROM (
    SELECT
        e.solving_time_id,
        CASE WHEN e.solving_time_id IS NULL THEN e.id END AS entry_id,
        MAX(e.earned_at) AS last_earned_at,
        MAX(e.created_at) AS last_created_at
    FROM xp_entry e
    WHERE e.player_id = :playerId
    GROUP BY e.solving_time_id, CASE WHEN e.solving_time_id IS NULL THEN e.id END
    ORDER BY last_earned_at DESC, last_created_at DESC, e.solving_time_id DESC NULLS LAST, entry_id DESC
    LIMIT :limit OFFSET :offset
) blocks
LEFT JOIN puzzle_solving_time pst ON pst.id = blocks.solving_time_id
LEFT JOIN puzzle p ON p.id = pst.puzzle_id
ORDER BY blocks.last_earned_at DESC, blocks.last_created_at DESC, blocks.solving_time_id DESC NULLS LAST, blocks.entry_id DESC
SQL;

        /** @var list<array{solving_time_id: null|string, entry_id: null|string, puzzle_id: null|string, puzzle_name: null|string, solved_at: null|string, puzzling_type: null|string, seconds_to_solve: null|int, earlier_solves: null|int|string}> $blockRows */
        $blockRows = $this->database
            ->executeQuery($blocksSql, ['playerId' => $playerId, 'limit' => $limit, 'offset' => $offset])
            ->fetchAllAssociative();

        if ($blockRows === []) {
            return [];
        }

        $solvingTimeIds = [];
        $entryIds = [];

        foreach ($blockRows as $row) {
            if ($row['solving_time_id'] !== null) {
                $solvingTimeIds[] = $row['solving_time_id'];
            } elseif ($row['entry_id'] !== null) {
                $entryIds[] = $row['entry_id'];
            }
        }

        $linesSql = <<<SQL
SELECT
    e.id,
    e.reason,
    e.amount,
    e.earned_at,
    e.solving_time_id
FROM xp_entry e
WHERE e.player_id = :playerId
  AND (e.solving_time_id IN (:solvingTimeIds) OR e.id IN (:entryIds))
ORDER BY e.earned_at ASC, e.created_at ASC, e.id ASC
SQL;

        /** @var list<array{id: string, reason: string, amount: int, earned_at: string, solving_time_id: null|string}> $lineRows */
        $lineRows = $this->database
            ->executeQuery($linesSql, [
                'playerId' => $playerId,
                // An empty IN () is invalid SQL - a never-matching id stands in
                'solvingTimeIds' => $solvingTimeIds !== [] ? $solvingTimeIds : ['00000000-0000-0000-0000-000000000000'],
                'entryIds' => $entryIds !== [] ? $entryIds : ['00000000-0000-0000-0000-000000000000'],
            ], [
                'solvingTimeIds' => ArrayParameterType::STRING,
                'entryIds' => ArrayParameterType::STRING,
            ])
            ->fetchAllAssociative();

        $linesByBlock = [];

        foreach ($lineRows as $row) {
            $key = $row['solving_time_id'] ?? $row['id'];
            $linesByBlock[$key][] = $row;
        }

        $blocks = [];

        foreach ($blockRows as $blockRow) {
            $key = $blockRow['solving_time_id'] ?? $blockRow['entry_id'];
            $rows = $key !== null ? ($linesByBlock[$key] ?? []) : [];

            if ($rows === []) {
                continue;
            }

            $lines = [];

            foreach ($rows as $row) {
                $lines[] = new XpHistoryEntry(
                    reason: XpReason::from($row['reason']),
                    amount: $row['amount'],
                    earnedAt: new DateTimeImmutable($row['earned_at']),
                    solvingTimeId: $row['solving_time_id'],
                    puzzleId: $blockRow['puzzle_id'],
                    puzzleName: $blockRow['puzzle_name'],
                );
            }

            $blocks[] = new XpHistoryBlock(
                solvingTimeId: $blockRow['solving_time_id'],
                puzzleId: $blockRow['puzzle_id'],
                puzzleName: $blockRow['puzzle_name'],
                solvedAt: $blockRow['solved_at'] !== null ? new DateTimeImmutable($blockRow['solved_at']) : null,
                puzzlingType: $blockRow['puzzling_type'],
                isTimed: $blockRow['seconds_to_solve'] !== null,
                occurrenceIndex: $blockRow['puzzle_id'] !== null && is_numeric($blockRow['earlier_solves']) ? (int) $blockRow['earlier_solves'] + 1 : null,
                lines: $lines,
            );
        }

        return $blocks;
    }

    public function countBlocksForPlayer(string $playerId): int
    {
        $sql = <<<SQL
SELECT COUNT(DISTINCT COALESCE(solving_time_id, id))
FROM xp_entry
WHERE player_id = :playerId
SQL;

        $value = $this->database
            ->executeQuery($sql, ['playerId' => $playerId])
            ->fetchOne();

        return is_numeric($value) ? (int) $value : 0;
    }
}
