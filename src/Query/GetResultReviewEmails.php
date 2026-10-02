<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Query;

use DateTimeImmutable;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use SpeedPuzzling\Web\Results\AutoRemovedResult;
use SpeedPuzzling\Web\Results\ResultReviewEmailCase;
use SpeedPuzzling\Web\Value\DuplicateCaseStatus;
use SpeedPuzzling\Web\Value\DuplicateKind;
use SpeedPuzzling\Web\Value\DuplicateTier;
use SpeedPuzzling\Web\Value\RemovedResultSnapshot;
use SpeedPuzzling\Web\Value\ResultReviewContactStatus;

/**
 * What the paced sending of "Your results" e-mails reads (docs/features/duplicate-results.md, "Sending"): the
 * planned e-mails in their order, how many went out today, and - at send time, the planning can be days old - what
 * of an e-mail is still true.
 */
readonly final class GetResultReviewEmails
{
    public function __construct(
        private Connection $database,
    ) {
    }

    /**
     * Weekly e-mails first, then the backlog: active players before dormant ones, the most recently active first.
     *
     * @return list<string>
     */
    public function plannedIdsInSendOrder(): array
    {
        /** @var list<string> $ids */
        $ids = $this->database->fetchFirstColumn(
            'SELECT id FROM result_review_contact WHERE status = :planned ORDER BY priority, last_active_on DESC NULLS LAST, planned_at, id',
            ['planned' => ResultReviewContactStatus::Planned->value],
        );

        return $ids;
    }

    public function countSentSince(DateTimeImmutable $since): int
    {
        $count = $this->database->fetchOne(
            'SELECT COUNT(*) FROM result_review_contact WHERE status = :sent AND sent_at >= :since',
            [
                'sent' => ResultReviewContactStatus::Sent->value,
                'since' => $since->format('Y-m-d H:i:s'),
            ],
        );
        assert(is_int($count) || is_string($count));

        return (int) $count;
    }

    /**
     * The cases still open with both copies there - the likely ones first, newest first within.
     *
     * @param list<string> $caseIds
     * @return list<ResultReviewEmailCase>
     */
    public function openCasesOf(string $playerId, array $caseIds): array
    {
        if ($caseIds === []) {
            return [];
        }

        $query = <<<SQL
SELECT
    CAST(c.id AS text) AS case_id,
    c.tier,
    c.kind,
    puzzle.name AS puzzle_name,
    a.seconds_to_solve,
    COALESCE(a.finished_at, a.tracked_at) AS solved_at
FROM result_duplicate_case c
INNER JOIN puzzle_solving_time a ON a.id = c.time_a_id
INNER JOIN puzzle_solving_time b ON b.id = c.time_b_id
INNER JOIN puzzle ON puzzle.id = a.puzzle_id
WHERE c.id IN (:caseIds)
    AND c.player_id = :playerId
    AND c.status = :open
ORDER BY CASE c.tier WHEN :possible THEN 1 ELSE 0 END, b.tracked_at DESC, c.id
SQL;

        /** @var list<array{case_id: string, tier: string, kind: string, puzzle_name: string, seconds_to_solve: null|int, solved_at: string}> $rows */
        $rows = $this->database->fetchAllAssociative($query, [
            'caseIds' => $caseIds,
            'playerId' => $playerId,
            'open' => DuplicateCaseStatus::Open->value,
            'possible' => DuplicateTier::Possible->value,
        ], [
            'caseIds' => ArrayParameterType::STRING,
        ]);

        return array_map(static fn (array $row): ResultReviewEmailCase => new ResultReviewEmailCase(
            caseId: $row['case_id'],
            tier: DuplicateTier::from($row['tier']),
            kind: DuplicateKind::from($row['kind']),
            puzzleName: $row['puzzle_name'],
            secondsToSolve: $row['seconds_to_solve'],
            solvedAt: new DateTimeImmutable($row['solved_at']),
        ), $rows);
    }

    /**
     * The automatic removals not brought back - newest first.
     *
     * @param list<string> $removalIds
     * @return list<AutoRemovedResult>
     */
    public function removalsNotUndoneOf(string $playerId, array $removalIds): array
    {
        if ($removalIds === []) {
            return [];
        }

        $query = <<<SQL
SELECT CAST(id AS text) AS id, CAST(kept_time_id AS text) AS kept_time_id, removed_at, snapshot
FROM result_auto_removal
WHERE id IN (:removalIds)
    AND player_id = :playerId
    AND undone_at IS NULL
ORDER BY removed_at DESC, id
SQL;

        /** @var list<array{id: string, kept_time_id: string, removed_at: string, snapshot: string}> $rows */
        $rows = $this->database->fetchAllAssociative($query, [
            'removalIds' => $removalIds,
            'playerId' => $playerId,
        ], [
            'removalIds' => ArrayParameterType::STRING,
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
}
